<?php
declare(strict_types=1);

namespace LuxAeterna\Core;

/** Aplica migraciones SQL numeradas (migrations/NNN_nombre.sql), cada una en su transacción. */
final class Migrator
{
    public function __construct(private readonly Database $db, private readonly string $dir)
    {
    }

    /** @return list<string> versiones disponibles */
    public function available(): array
    {
        $out = [];
        foreach (glob($this->dir . '/[0-9][0-9][0-9]_*.sql') ?: [] as $f) {
            $out[] = basename($f, '.sql');
        }
        sort($out);
        return $out;
    }

    /** @return list<string> */
    public function applied(): array
    {
        $this->db->pdo()->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version TEXT PRIMARY KEY, applied_at INTEGER NOT NULL)');
        return array_map(static fn(array $r): string => (string)$r['version'], $this->db->all('SELECT version FROM schema_migrations ORDER BY version'));
    }

    public function isCurrent(): bool
    {
        return array_diff($this->available(), $this->applied()) === [];
    }

    /** @return list<string> migraciones aplicadas ahora */
    public function migrate(): array
    {
        $done = [];
        $applied = $this->applied();
        foreach ($this->available() as $version) {
            if (in_array($version, $applied, true)) {
                continue;
            }
            $sql = (string)file_get_contents($this->dir . '/' . $version . '.sql');
            $this->db->transaction(function () use ($sql, $version): void {
                $this->db->pdo()->exec($sql);
                $this->db->run('INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)', [$version, time()]);
            });
            $done[] = $version;
        }
        return $done;
    }
}
