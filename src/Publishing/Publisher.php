<?php
declare(strict_types=1);

namespace LuxAeterna\Publishing;

use LuxAeterna\Content\ContentLoader;
use LuxAeterna\Content\SiteSnapshot;
use LuxAeterna\Core\Config;
use LuxAeterna\Core\Fs;
use LuxAeterna\Core\Logger;
use LuxAeterna\Core\LuxException;

/**
 * Flujo vertical mínimo: contenido → plantilla → versión en un directorio temporal →
 * preflight → versión definitiva → activación atómica.
 */
final class Publisher
{
    public function __construct(
        private readonly Config $config,
        private readonly ReleaseStore $store,
        private readonly Logger $logger,
        private readonly string $layoutDir,
    ) {
    }

    /** Publica el contenido de ejemplo de un archivo JSON (demostración y pruebas). @return array{id:string,pages:int,strategy:string,removed:list<string>,warnings:list<string>} */
    public function publish(string $contentFile): array
    {
        $pages = (new ContentLoader())->load($contentFile);
        $nav = [];
        foreach ($pages as $p) {
            if ($p->inMenu) {
                $nav[] = ['label' => $p->title, 'url' => $p->path()];
            }
        }
        return $this->publishSnapshot(new SiteSnapshot($pages, ['main' => $nav]));
    }

    /** @return array{id:string,pages:int,strategy:string,removed:list<string>,warnings:list<string>} */
    public function publishSnapshot(SiteSnapshot $snapshot): array
    {
        $this->store->init();
        $lock = fopen($this->store->base . '/.publish.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new LuxException('Ya hay una publicación en curso.');
        }
        try {
            $id = $this->store->newId();
            $tmp = $this->store->releasesDir() . '/.building-' . $id;
            try {
                $this->render($snapshot, $tmp, $id);
                $errors = (new Preflight())->check($tmp);
                if ($errors !== []) {
                    throw new LuxException('La comprobación previa ha fallado: ' . $errors[0] . (count($errors) > 1 ? ' (+' . (count($errors) - 1) . ' más)' : ''), implode("\n", $errors));
                }
                if (!@rename($tmp, $this->store->releasePath($id))) {
                    throw new LuxException('No se pudo guardar la versión.', "rename $tmp");
                }
            } catch (\Throwable $e) {
                if (is_dir($tmp)) {
                    Fs::removeTree($tmp, $this->store->releasesDir());
                }
                throw $e;
            }
            $activator = new Activator($this->store, (string)$this->config->get('publishing.activation', 'auto'));
            $activator->activate($id);
            $removed = $this->store->prune((int)$this->config->get('publishing.keep_releases', 5));
            $this->logger->info('publicación completada', ['release' => $id, 'pages' => count($snapshot->pages), 'strategy' => $activator->strategy()]);
            return ['id' => $id, 'pages' => count($snapshot->pages), 'strategy' => $activator->strategy(), 'removed' => $removed, 'warnings' => $snapshot->warnings];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function render(SiteSnapshot $snap, string $out, string $id): void
    {
        $r = new SiteRenderer($this->config, $this->layoutDir);
        $cssPath = $r->publicStylesheet();
        Fs::ensureDir($out . '/assets', 0755);
        Fs::atomicWrite($out . $cssPath, $r->css, 0644);
        $site = $r->siteVars($snap, $cssPath);
        foreach ($snap->pages as $p) {
            $this->write($out, $p->path(), $r->page($p, $site));
        }
        foreach ($snap->redirects as $rd) {
            $this->write($out, $rd['from'], $r->redirectStub($rd['to']));
        }
        Fs::atomicWrite($out . '/404.html', $r->notFound($site), 0644);
        Fs::atomicWrite($out . '/' . ReleaseStore::META, (string)json_encode(['id' => $id, 'created' => gmdate('c'), 'pages' => count($snap->pages)]), 0644);
    }

    private function write(string $out, string $path, string $html): void
    {
        $target = $path === '/' ? '/index.html' : rtrim($path, '/') . '/index.html';
        Fs::ensureDir(dirname($out . $target), 0755);
        Fs::atomicWrite($out . $target, $html, 0644);
    }
}
