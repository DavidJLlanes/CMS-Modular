<?php
declare(strict_types=1);

namespace LuxAeterna\Publishing;

use LuxAeterna\Core\Fs;
use LuxAeterna\Core\LuxException;

/**
 * Activa una versión de forma atómica (ADR-0003 y enmienda ADR-0007).
 *  - symlink: renombra un enlace temporal sobre `current` (rename(2) es atómico).
 *  - rename:  para hostings sin enlaces simbólicos; intercambia directorios y deja un marcador
 *             de recuperación por si el proceso muere entre ambos renombrados.
 */
final class Activator
{
    public const SYMLINK = 'symlink';
    public const RENAME = 'rename';

    public function __construct(private readonly ReleaseStore $store, private readonly string $mode = 'auto')
    {
    }

    public function strategy(): string
    {
        if ($this->mode === self::SYMLINK || $this->mode === self::RENAME) {
            return $this->mode;
        }
        if (is_dir($this->store->currentPath()) && !is_link($this->store->currentPath())) {
            return self::RENAME;
        }
        return self::supportsSymlinks($this->store->base) ? self::SYMLINK : self::RENAME;
    }

    public static function supportsSymlinks(string $dir): bool
    {
        if (!function_exists('symlink')) {
            return false;
        }
        $link = $dir . '/.symlink-test-' . bin2hex(random_bytes(3));
        $ok = @symlink('releases', $link);
        if ($ok) {
            $ok = is_link($link) && @readlink($link) === 'releases';
            @unlink($link);
        }
        return $ok;
    }

    public function activate(string $id): void
    {
        $this->recover();
        $src = $this->store->releasePath($id);
        if (!is_dir($src) && $this->store->currentId() === $id) {
            return; // ya activa
        }
        if (!is_file($src . '/index.html')) {
            throw new LuxException('La versión no existe o está incompleta.', "activate $id");
        }
        $this->strategy() === self::SYMLINK ? $this->viaSymlink($id) : $this->viaRename($id);
    }

    private function viaSymlink(string $id): void
    {
        $cur = $this->store->currentPath();
        if (is_dir($cur) && !is_link($cur)) {
            throw new LuxException('`current` es un directorio; cambia la estrategia a "rename" o conviértelo.', "current dir");
        }
        $tmp = $this->store->base . '/.current-' . bin2hex(random_bytes(3));
        if (!@symlink('releases/' . $id, $tmp) || !@rename($tmp, $cur)) {
            @unlink($tmp);
            throw new LuxException('No se pudo activar la versión.', "symlink swap $id");
        }
    }

    private function viaRename(string $id): void
    {
        $cur = $this->store->currentPath();
        $new = $this->store->releasePath($id);
        $oldId = is_dir($cur) ? $this->store->currentId() : null;
        if (is_dir($cur) && $oldId === null) {
            throw new LuxException('La versión activa no tiene metadatos; no se puede intercambiar con seguridad.');
        }
        $marker = $this->store->base . '/.activating.json';
        Fs::atomicWrite($marker, (string)json_encode(['from' => $oldId, 'to' => $id]));
        if ($oldId !== null && !@rename($cur, $this->store->releasePath($oldId))) {
            @unlink($marker);
            throw new LuxException('No se pudo retirar la versión activa.', "rename current -> $oldId");
        }
        if (!@rename($new, $cur)) {
            if ($oldId !== null) {
                @rename($this->store->releasePath($oldId), $cur); // volver atrás
            }
            @unlink($marker);
            throw new LuxException('No se pudo activar la versión; se mantiene la anterior.', "rename $id -> current");
        }
        @unlink($marker);
    }

    /** Si un proceso murió entre los dos renombrados, restaura la versión anterior. */
    public function recover(): bool
    {
        $marker = $this->store->base . '/.activating.json';
        if (!is_file($marker)) {
            return false;
        }
        $m = json_decode((string)file_get_contents($marker), true);
        $cur = $this->store->currentPath();
        if (is_array($m) && !is_dir($cur)) {
            $from = $m['from'] ?? null;
            $to = $m['to'] ?? null;
            // Se prefiere restaurar la versión anterior: es la que estaba sirviéndose.
            if (is_string($from) && is_dir($this->store->releasePath($from))) {
                @rename($this->store->releasePath($from), $cur);
            } elseif (is_string($to) && is_dir($this->store->releasePath($to))) {
                @rename($this->store->releasePath($to), $cur);
            }
        }
        @unlink($marker);
        return true;
    }
}
