<?php
declare(strict_types=1);

namespace LuxAeterna\Publishing;

/**
 * Comprobaciones previas a activar una versión. Distingue ERRORES (bloquean la activación) de AVISOS editoriales
 * (se registran y se muestran, pero no bloquean).
 */
final class Preflight
{
    /** Extensiones que nunca deben acabar en una versión pública. */
    private const FORBIDDEN = ['php', 'phtml', 'phar', 'sqlite', 'sqlite3', 'db', 'sql', 'env', 'log', 'bak', 'key', 'pem'];
    /** Archivos ocultos permitidos en la raíz de una versión. */
    private const ALLOWED_DOT = ['.lux-release.json', '.htaccess', '.lux-redirects.nginx.conf', '.lux-inventory.json'];
    private const LIMITS = ['max_page_bytes' => 4194304, 'warn_page_bytes' => 1048576, 'max_files' => 50000, 'base_url' => ''];

    /** @return list<string> errores bloqueantes (compatibilidad con la Fase 1) */
    public function check(string $dir): array
    {
        return $this->run($dir)['errors'];
    }

    /**
     * @param array<string,mixed> $limits
     * @return array{errors:list<string>,warnings:list<string>,files:int,bytes:int}
     */
    public function run(string $dir, array $limits = []): array
    {
        $lim = $limits + self::LIMITS;
        $errors = [];
        $warnings = [];
        foreach (['index.html', '404.html'] as $required) {
            if (!is_file("$dir/$required")) {
                $errors[] = "Falta $required.";
            }
        }
        $lower = [];
        $files = 0;
        $bytes = 0;
        $titles = [];
        $stubTargets = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            $rel = substr($file->getPathname(), strlen($dir) + 1);
            if ($file->isLink()) {
                $errors[] = "Enlace simbólico en la versión: $rel";
                continue;
            }
            $files++;
            $bytes += (int)$file->getSize();
            $key = strtolower($rel);
            if (isset($lower[$key])) {
                $errors[] = "Colisión de rutas que solo difieren en mayúsculas: {$lower[$key]} y $rel";
            }
            $lower[$key] = $rel;
            $name = $file->getFilename();
            if ($name[0] === '.' && !($rel === $name && in_array($name, self::ALLOWED_DOT, true))) {
                $errors[] = "Archivo oculto no permitido en la versión: $rel";
            }
            if (in_array(strtolower($file->getExtension()), self::FORBIDDEN, true)) {
                $errors[] = "Archivo privado o ejecutable en la versión: $rel";
            }
            if (strtolower($file->getExtension()) === 'css') {
                $css = (string)file_get_contents($file->getPathname());
                if (preg_match('/@import\b|url\(\s*[\'"]?\s*(?:https?:)?\/\//i', $css)) {
                    $errors[] = "Dependencia remota en el CSS: $rel";
                }
            }
            if (strtolower($file->getExtension()) === 'html') {
                $this->html($dir, $rel, (string)file_get_contents($file->getPathname()), (int)$file->getSize(), $lim, $errors, $warnings, $titles, $stubTargets);
            }
        }
        if ($files > (int)$lim['max_files']) {
            $errors[] = "La versión tiene $files archivos (límite {$lim['max_files']}).";
        }
        foreach ($titles as $title => $pages) {
            if (count($pages) > 1) {
                $warnings[] = 'Título repetido «' . $title . '» en: ' . implode(', ', array_slice($pages, 0, 3)) . (count($pages) > 3 ? '…' : '');
            }
        }
        foreach ($stubTargets as $from => $to) {
            if (isset($stubTargets[$to])) {
                $warnings[] = "Cadena de redirecciones: $from → $to → {$stubTargets[$to]}.";
            }
        }
        return ['errors' => $errors, 'warnings' => $warnings, 'files' => $files, 'bytes' => $bytes];
    }

    /**
     * @param array<string,mixed> $lim @param list<string> $errors @param list<string> $warnings
     * @param array<string,list<string>> $titles @param array<string,string> $stubTargets
     */
    private function html(string $dir, string $rel, string $html, int $size, array $lim, array &$errors, array &$warnings, array &$titles, array &$stubTargets): void
    {
        if ($size > (int)$lim['max_page_bytes']) {
            $errors[] = "Página demasiado grande ($size bytes): $rel";
        } elseif ($size > (int)$lim['warn_page_bytes']) {
            $warnings[] = "Página pesada ($size bytes): $rel";
        }
        $isStub = (bool)preg_match('/<meta http-equiv="refresh"/i', $html);
        if ($isStub && preg_match('/<meta http-equiv="refresh" content="0; url=([^"]+)"/i', $html, $m)) {
            $to = html_entity_decode($m[1], ENT_QUOTES);
            $stubTargets['/' . preg_replace('#index\.html$#', '', $rel)] = $to;
        }
        if (!$isStub) {
            if (preg_match('/<meta name="robots" content="[^"]*noindex/i', $html)) {
                $errors[] = "Página pública con noindex accidental: $rel";
            }
            $canon = preg_match('/<link rel="canonical" href="([^"]*)"/i', $html, $m) ? html_entity_decode($m[1], ENT_QUOTES) : '';
            if ($canon === '') {
                $warnings[] = "Sin enlace canónico: $rel";
            } elseif ($lim['base_url'] !== '' && !str_starts_with($canon, (string)$lim['base_url'] . '/')) {
                $errors[] = "El canónico no pertenece al sitio ($canon): $rel";
            }
            $desc = preg_match('/<meta name="description" content="([^"]*)"/i', $html, $m) ? trim(html_entity_decode($m[1], ENT_QUOTES)) : '';
            if ($desc === '' && $rel !== '404.html') {
                $warnings[] = "Sin descripción (resumen): $rel";
            } elseif (mb_strlen($desc) > 170) {
                $warnings[] = 'Descripción demasiado larga (' . mb_strlen($desc) . "): $rel";
            }
            $title = preg_match('/<title>(.*?)<\/title>/is', $html, $m) ? trim(html_entity_decode($m[1], ENT_QUOTES)) : '';
            if ($title === '') {
                $errors[] = "Sin título: $rel";
            } else {
                if (mb_strlen($title) > 70) {
                    $warnings[] = 'Título demasiado largo (' . mb_strlen($title) . "): $rel";
                }
                if ($rel !== '404.html') {
                    $titles[$title][] = '/' . preg_replace('#index\.html$#', '', $rel);
                }
            }
        }
        if (preg_match_all('/<(link|script|img|iframe|source|video|audio|embed|object|input)\b[^>]*>/i', $html, $tags, PREG_SET_ORDER)) {
            foreach ($tags as $t) {
                if (!preg_match('/\s(?:src|href|data|poster|srcset)\s*=\s*["\']?\s*(?:https?:)?\/\/([^"\' >]+)/i', $t[0], $u)) {
                    continue;
                }
                if (strtolower($t[1]) === 'link' && preg_match('/\srel\s*=\s*["\']?(?:canonical|alternate|author|license|me|next|prev|help)\b/i', $t[0])) {
                    continue;
                }
                $errors[] = 'Dependencia remota (' . strtolower($t[1]) . ' → ' . substr($u[1], 0, 60) . ") en $rel";
            }
        }
        if (preg_match_all('/\b(?:href|src|action)=(["\'])([^"\']*)\1/i', $html, $links)) {
            foreach (array_unique($links[2]) as $raw) {
                $this->link($dir, $rel, html_entity_decode($raw, ENT_QUOTES), $errors, $warnings);
            }
        }
        if ($isStub) {
            return;
        }
    }

    /** @param list<string> $errors @param list<string> $warnings */
    private function link(string $dir, string $rel, string $url, array &$errors, array &$warnings): void
    {
        if ($url === '' || $url[0] === '#' || preg_match('#^(?:https?:)?//#i', $url) || preg_match('/^(?:mailto|tel):/i', $url)) {
            return;
        }
        if (preg_match('/^(?:javascript|data|vbscript):/i', $url)) {
            $errors[] = "Enlace no permitido ($url) en $rel";
            return;
        }
        $path = rawurldecode((string)strtok($url, '?#'));
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . (dirname($rel) === '.' ? '' : dirname($rel) . '/') . $path;
        }
        $path = $this->normalize($path);
        if ($path === null) {
            $errors[] = "Enlace que sale de la raíz ($url) en $rel";
            return;
        }
        $r = ltrim($path, '/');
        if ($r === '' || str_ends_with($path, '/')) {
            $ok = is_file("$dir/{$r}index.html");
        } elseif (is_file("$dir/$r")) {
            $ok = true;
        } else {
            $ok = is_file("$dir/$r/index.html");
            if ($ok) {
                $warnings[] = "Enlace sin barra final ($url) en $rel";
            }
        }
        if (!$ok) {
            $errors[] = "Enlace interno roto $url en $rel";
        }
    }

    private function normalize(string $path): ?string
    {
        $out = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                if ($out === []) {
                    return null;
                }
                array_pop($out);
                continue;
            }
            $out[] = $seg;
        }
        return '/' . implode('/', $out) . (str_ends_with($path, '/') && $out !== [] ? '/' : '');
    }
}
