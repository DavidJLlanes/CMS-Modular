<?php
declare(strict_types=1);

namespace LuxAeterna\Publishing;

use LuxAeterna\Content\ContentLoader;
use LuxAeterna\Content\SiteSnapshot;
use LuxAeterna\Core\Config;
use LuxAeterna\Core\Fs;
use LuxAeterna\Core\Logger;
use LuxAeterna\Core\LuxException;
use LuxAeterna\Core\TransientException;

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
    public function publish(string $contentFile, bool $force = false): array
    {
        $pages = (new ContentLoader())->load($contentFile);
        $nav = [];
        foreach ($pages as $p) {
            if ($p->inMenu) {
                $nav[] = ['label' => $p->title, 'url' => $p->path()];
            }
        }
        return $this->publishSnapshot(new SiteSnapshot($pages, ['main' => $nav]), ['force' => $force]);
    }

    /**
     * @param array{force?:bool} $opts
     * @return array{id:string,pages:int,strategy:string,removed:list<string>,warnings:list<string>,skipped:bool,hash:string}
     */
    public function publishSnapshot(SiteSnapshot $snapshot, array $opts = []): array
    {
        $this->store->init();
        $lock = fopen($this->store->base . '/.publish.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new TransientException('Ya hay una publicación en curso.');
        }
        try {
            $this->cleanBuilding();
            $this->guardSpace();
            $id = $this->store->newId();
            $tmp = $this->store->releasesDir() . '/.building-' . $id;
            try {
                $meta = $this->render($snapshot, $tmp, $id);
                $pf = (new Preflight())->run($tmp, [
                    'base_url' => rtrim((string)$this->config->get('site.base_url', ''), '/'),
                    'max_files' => (int)$this->config->get('publishing.max_files', 50000),
                ]);
                if ($pf['errors'] !== []) {
                    throw new LuxException('La comprobación previa ha fallado: ' . $pf['errors'][0] . (count($pf['errors']) > 1 ? ' (+' . (count($pf['errors']) - 1) . ' más)' : ''), implode("\n", $pf['errors']));
                }
                $warnings = array_values(array_unique(array_merge($snapshot->warnings, $pf['warnings'])));
                $cur = $this->store->currentId();
                if (empty($opts['force']) && $cur !== null && ($this->store->meta($cur)['hash'] ?? null) === $meta['hash']) {
                    Fs::removeTree($tmp, $this->store->releasesDir());
                    return ['id' => $cur, 'pages' => count($snapshot->pages), 'strategy' => (new Activator($this->store, (string)$this->config->get('publishing.activation', 'auto')))->strategy(), 'removed' => [], 'warnings' => $warnings, 'skipped' => true, 'hash' => $meta['hash']];
                }
                $meta['warnings'] = array_slice($warnings, 0, 50);
                $meta['files'] = $pf['files'];
                $meta['bytes'] = $pf['bytes'];
                Fs::atomicWrite($tmp . '/' . ReleaseStore::META, (string)json_encode($meta, JSON_UNESCAPED_SLASHES), 0644);
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
            return ['id' => $id, 'pages' => count($snapshot->pages), 'strategy' => $activator->strategy(), 'removed' => $removed, 'warnings' => $warnings, 'skipped' => false, 'hash' => $meta['hash']];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Borra restos de construcciones interrumpidas (se llama con el bloqueo tomado, así que ninguna está viva). */
    private function cleanBuilding(): void
    {
        foreach (@scandir($this->store->releasesDir()) ?: [] as $e) {
            if (str_starts_with($e, '.building-')) {
                Fs::removeTree($this->store->releasesDir() . '/' . $e, $this->store->releasesDir());
            }
        }
    }

    private function guardSpace(): void
    {
        $min = (int)$this->config->get('publishing.min_free_mb', 50) * 1048576;
        $free = Fs::freeSpace($this->store->base);
        if ($free !== null && $free < $min) {
            throw new LuxException('Espacio en disco insuficiente para publicar.', "libre=$free mínimo=$min");
        }
    }

    /** @return array<string,mixed> metadatos (sin escribir) */
    private function render(SiteSnapshot $snap, string $out, string $id): array
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
        $rules = RuleBuilder::clean($snap->redirects);
        Fs::atomicWrite($out . '/.lux-redirects.nginx.conf', RuleBuilder::nginx($rules), 0644);
        Fs::atomicWrite($out . '/.htaccess', RuleBuilder::apache($rules, "Options -Indexes\nErrorDocument 404 /404.html"), 0644);
        $own = [$cssPath];
        $kept = $this->carryAssets($out, $own);
        $hash = $this->treeHash($out);
        return ['id' => $id, 'created' => gmdate('c'), 'pages' => count($snap->pages), 'hash' => $hash, 'assets_own' => $own, 'assets' => array_merge($own, $kept), 'redirects' => count($rules)];
    }

    /** Hash determinista del contenido generado (contenido + configuración + plantilla). */
    private function treeHash(string $dir): string
    {
        $parts = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            $rel = substr($f->getPathname(), strlen($dir) + 1);
            if ($rel !== ReleaseStore::META) {
                $parts[$rel] = hash_file('sha256', $f->getPathname());
            }
        }
        ksort($parts);
        $s = '';
        foreach ($parts as $k => $v) {
            $s .= "$k\0$v\n";
        }
        return hash('sha256', $s);
    }

    /**
     * Copia a la versión nueva los recursos con hash de las últimas K versiones, para que páginas en caché
     * de visitantes (que referencian CSS antiguo) no den 404 tras publicar. @param list<string> $own @return list<string> */
    private function carryAssets(string $out, array $own): array
    {
        $k = (int)$this->config->get('publishing.keep_assets_releases', 2);
        $kept = [];
        if ($k < 1) {
            return $kept;
        }
        $prev = array_slice(array_reverse($this->store->all()), 0, $k);
        foreach ($prev as $pid) {
            $m = $this->store->meta($pid);
            $base = $pid === $this->store->currentId() && !is_dir($this->store->releasePath($pid)) ? $this->store->currentPath() : $this->store->releasePath($pid);
            foreach ((array)($m['assets_own'] ?? []) as $a) {
                $a = (string)$a;
                if (!preg_match('#^/assets/[A-Za-z0-9._-]+$#', $a) || in_array($a, $own, true) || in_array($a, $kept, true) || !is_file($base . $a)) {
                    continue;
                }
                if (!@link($base . $a, $out . $a) && !@copy($base . $a, $out . $a)) {
                    continue;
                }
                $kept[] = $a;
            }
        }
        return $kept;
    }

    private function write(string $out, string $path, string $html): void
    {
        $target = $path === '/' ? '/index.html' : rtrim($path, '/') . '/index.html';
        Fs::ensureDir(dirname($out . $target), 0755);
        Fs::atomicWrite($out . $target, $html, 0644);
    }
}
