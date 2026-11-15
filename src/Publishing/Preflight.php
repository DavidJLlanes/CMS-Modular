<?php
declare(strict_types=1);

namespace LuxAeterna\Publishing;

/** Comprobaciones previas a activar una versión (versión mínima de la Fase 1; se amplía en la Fase 4). */
final class Preflight
{
    /** Extensiones que nunca deben acabar en una versión pública. */
    private const FORBIDDEN = ['php', 'phtml', 'phar', 'sqlite', 'sqlite3', 'db', 'sql', 'env', 'log', 'bak', 'key', 'pem'];

    /** @return list<string> errores bloqueantes (vacío si todo está bien) */
    public function check(string $dir): array
    {
        $errors = [];
        foreach (['index.html', '404.html'] as $required) {
            if (!is_file("$dir/$required")) {
                $errors[] = "Falta $required.";
            }
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isLink()) {
                $errors[] = 'Enlace simbólico en la versión: ' . substr($file->getPathname(), strlen($dir) + 1);
                continue;
            }
            $ext = strtolower($file->getExtension());
            if (in_array($ext, self::FORBIDDEN, true)) {
                $errors[] = 'Archivo privado o ejecutable en la versión: ' . substr($file->getPathname(), strlen($dir) + 1);
            }
            if ($ext === 'html') {
                array_push($errors, ...$this->brokenLinks($dir, $file->getPathname()));
            }
        }
        return $errors;
    }

    /** @return list<string> */
    private function brokenLinks(string $dir, string $htmlFile): array
    {
        $html = (string)file_get_contents($htmlFile);
        $errors = [];
        if (preg_match_all('/\b(?:href|src)="(\/[^"#?]*)/', $html, $m)) {
            foreach (array_unique($m[1]) as $url) {
                $rel = ltrim(rawurldecode($url), '/');
                $ok = $rel === '' || is_file("$dir/$rel") || is_file("$dir/" . rtrim($rel, '/') . '/index.html');
                if (!$ok) {
                    $errors[] = 'Enlace interno roto ' . $url . ' en ' . substr($htmlFile, strlen($dir) + 1);
                }
            }
        }
        return $errors;
    }
}
