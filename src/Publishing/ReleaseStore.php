<?php
declare(strict_types=1);

namespace LuxAeterna\Publishing;

use LuxAeterna\Core\Fs;
use LuxAeterna\Core\LuxException;

/**
 * Directorio operativo de publicación (separado del código):
 *   <publish>/releases/<id>/   versiones generadas
 *   <publish>/current          versión activa (enlace simbólico, o directorio en modo "rename")
 */
final class ReleaseStore
{
    public const META = '.lux-release.json';
    /** AAAAMMDDHHMMSSuuuuuu-xxxx: ordenable cronológicamente (microsegundos) con sufijo aleatorio. */
    public const ID_PATTERN = '/^\d{20}-[0-9a-f]{4}$/';

    public function __construct(public readonly string $base)
    {
    }

    public function releasesDir(): string
    {
        return $this->base . '/releases';
    }

    public function currentPath(): string
    {
        return $this->base . '/current';
    }

    public function releasePath(string $id): string
    {
        $this->assertId($id);
        return $this->releasesDir() . '/' . $id;
    }

    public function assertId(string $id): void
    {
        if (!preg_match(self::ID_PATTERN, $id)) {
            throw new LuxException('Identificador de versión no válido.', "id=$id");
        }
    }

    /** true si ningún proceso mantiene el bloqueo de publicación. */
    public function lockFree(): bool
    {
        $f = @fopen($this->base . '/.publish.lock', 'c');
        if ($f === false) {
            return false;
        }
        $ok = flock($f, LOCK_EX | LOCK_NB);
        if ($ok) {
            flock($f, LOCK_UN);
        }
        fclose($f);
        return $ok;
    }

    public function init(): void
    {
        Fs::ensureDir($this->releasesDir(), 0755);
    }

    public function newId(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('YmdHisu') . '-' . bin2hex(random_bytes(2));
    }

    /** @return array<string,mixed>|null metadatos de una versión */
    public function meta(string $id): ?array
    {
        $raw = @file_get_contents($this->releasePath($id) . '/' . self::META);
        if ($raw === false && $id === $this->currentId()) {
            $raw = @file_get_contents($this->currentPath() . '/' . self::META);
        }
        $d = $raw === false ? null : json_decode($raw, true);
        return is_array($d) ? $d : null;
    }

    /** Id de la versión activa, o null si no hay ninguna. */
    public function currentId(): ?string
    {
        $cur = $this->currentPath();
        if (is_link($cur)) {
            $target = basename((string)readlink($cur));
            return preg_match(self::ID_PATTERN, $target) ? $target : null;
        }
        if (is_dir($cur)) {
            $meta = @file_get_contents($cur . '/' . self::META);
            $data = $meta === false ? null : json_decode($meta, true);
            return is_array($data) && isset($data['id']) ? (string)$data['id'] : null;
        }
        return null;
    }

    /** @return list<string> ids almacenados en releases/ (más antiguo primero), sin la activa en modo rename. */
    public function stored(): array
    {
        $ids = [];
        foreach (@scandir($this->releasesDir()) ?: [] as $e) {
            if (preg_match(self::ID_PATTERN, $e) && is_dir($this->releasesDir() . '/' . $e)) {
                $ids[] = $e;
            }
        }
        sort($ids);
        return $ids;
    }

    /** @return list<string> todas las versiones conocidas, incluida la activa. */
    public function all(): array
    {
        $ids = $this->stored();
        $cur = $this->currentId();
        if ($cur !== null && !in_array($cur, $ids, true)) {
            $ids[] = $cur;
        }
        sort($ids);
        return $ids;
    }

    /** Conserva las $keep versiones más recientes y la activa; borra el resto. @return list<string> borradas */
    public function prune(int $keep): array
    {
        $current = $this->currentId();
        $stored = $this->stored();
        $removed = [];
        $protected = array_slice($stored, max(0, count($stored) - max(1, $keep)));
        foreach ($stored as $id) {
            if ($id === $current || in_array($id, $protected, true)) {
                continue;
            }
            Fs::removeTree($this->releasePath($id), $this->releasesDir());
            $removed[] = $id;
        }
        return $removed;
    }
}
