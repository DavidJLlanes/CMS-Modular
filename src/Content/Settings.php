<?php
declare(strict_types=1);

namespace LuxAeterna\Content;

use LuxAeterna\Core\Database;

final class Settings
{
    public function __construct(private readonly Database $db)
    {
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $v = $this->db->val('SELECT value FROM settings WHERE key = ?', [$key]);
        return $v === null ? $default : (string)$v;
    }

    public function set(string $key, ?string $value): void
    {
        if ($value === null) {
            $this->db->run('DELETE FROM settings WHERE key = ?', [$key]);
            return;
        }
        $this->db->run('INSERT INTO settings (key, value, updated_at) VALUES (?, ?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at', [$key, $value, time()]);
    }
}
