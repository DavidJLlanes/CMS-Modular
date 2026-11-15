<?php
declare(strict_types=1);

namespace LuxAeterna\Publishing;

use LuxAeterna\Content\ContentLoader;
use LuxAeterna\Content\Page;
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

    /** @return array{id:string,pages:int,strategy:string,removed:list<string>} */
    public function publish(string $contentFile): array
    {
        $this->store->init();
        $lock = fopen($this->store->base . '/.publish.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new LuxException('Ya hay una publicación en curso.');
        }
        try {
            $pages = (new ContentLoader())->load($contentFile);
            $id = $this->store->newId();
            $tmp = $this->store->releasesDir() . '/.building-' . $id;
            try {
                $this->render($pages, $tmp, $id);
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
            $this->logger->info('publicación completada', ['release' => $id, 'pages' => count($pages), 'strategy' => $activator->strategy()]);
            return ['id' => $id, 'pages' => count($pages), 'strategy' => $activator->strategy(), 'removed' => $removed];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param list<Page> $pages */
    private function render(array $pages, string $out, string $id): void
    {
        $tpl = $this->read($this->layoutDir . '/template.html');
        $css = $this->read($this->layoutDir . '/style.css');
        $hash = substr(hash('sha256', $css), 0, 10);
        $cssPath = "/assets/style.$hash.css";
        Fs::ensureDir($out . '/assets', 0755);
        Fs::atomicWrite($out . $cssPath, $css, 0644);

        $nav = '';
        foreach ($pages as $p) {
            if ($p->inMenu) {
                $nav .= '<a href="' . htmlspecialchars($p->path(), ENT_QUOTES) . '">' . htmlspecialchars($p->title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';
            }
        }
        $site = [
            'lang' => (string)$this->config->get('site.lang', 'es'),
            'site_name' => (string)$this->config->get('site.name', 'LuxAeterna'),
            'nav' => $nav,
            'stylesheet' => $cssPath,
        ];
        foreach ($pages as $p) {
            $html = Template::render($tpl, $site + [
                'title' => $p->title,
                'heading' => $p->title,
                'description' => $p->description,
                'canonical' => $this->config->baseUrl() . $p->path(),
                'content' => BlockRenderer::render($p),
            ]);
            $target = $p->slug === '' ? '/index.html' : '/' . $p->slug . '/index.html';
            Fs::ensureDir(dirname($out . $target), 0755);
            Fs::atomicWrite($out . $target, $html, 0644);
        }
        $nf = Template::render($tpl, $site + [
            'title' => 'Página no encontrada',
            'heading' => 'Página no encontrada',
            'description' => '',
            'canonical' => $this->config->baseUrl() . '/',
            'content' => "<p>La página que buscas no existe. <a href=\"/\">Volver al inicio</a>.</p>\n",
        ]);
        Fs::atomicWrite($out . '/404.html', $nf, 0644);
        Fs::atomicWrite($out . '/' . ReleaseStore::META, (string)json_encode(['id' => $id, 'created' => gmdate('c'), 'pages' => count($pages)]), 0644);
    }

    private function read(string $file): string
    {
        $c = @file_get_contents($file);
        if ($c === false) {
            throw new LuxException('Falta un archivo de diseño.', "read $file");
        }
        return $c;
    }
}
