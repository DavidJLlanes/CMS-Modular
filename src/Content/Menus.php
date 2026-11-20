<?php
declare(strict_types=1);

namespace CMSModular\Content;

use CMSModular\Core\Database;
use CMSModular\Core\LuxException;

/** Menús configurables. Cada elemento apunta a un contenido por identificador estable o a una URL. */
final class Menus
{
    public const MENUS = ['main' => 'Menú principal', 'footer' => 'Pie de página'];

    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function items(string $menu): array
    {
        return $this->db->all('SELECT * FROM menu_items WHERE menu = ? ORDER BY position, id', [$menu]);
    }

    public function add(string $menu, string $label, ?string $targetUid, ?string $url): int
    {
        $label = trim($label);
        if (!isset(self::MENUS[$menu]) || $label === '' || mb_strlen($label) > 60) {
            throw new LuxException('El texto del enlace debe tener entre 1 y 60 caracteres.');
        }
        if (($targetUid === null) === ($url === null)) {
            throw new LuxException('Indica un contenido o una dirección, pero no ambos.');
        }
        if ($targetUid !== null && $this->db->val('SELECT 1 FROM contents WHERE uid = ?', [$targetUid]) === null) {
            throw new LuxException('El contenido elegido no existe.');
        }
        if ($url !== null && !self::validUrl($url)) {
            throw new LuxException('La dirección debe ser una ruta interna (/…) o una URL https.');
        }
        if ((int)$this->db->val('SELECT COUNT(*) FROM menu_items WHERE menu = ?', [$menu]) >= 50) {
            throw new LuxException('Máximo 50 elementos por menú.');
        }
        $pos = (int)$this->db->val('SELECT COALESCE(MAX(position), 0) + 1 FROM menu_items WHERE menu = ?', [$menu]);
        $this->db->run('INSERT INTO menu_items (menu, position, label, target_uid, url) VALUES (?, ?, ?, ?, ?)', [$menu, $pos, $label, $targetUid, $url]);
        return $this->db->lastId();
    }

    public function remove(int $id): void
    {
        $this->db->run('DELETE FROM menu_items WHERE id = ?', [$id]);
    }

    public function move(int $id, int $direction): void
    {
        $this->db->transaction(function () use ($id, $direction): void {
            $it = $this->db->one('SELECT * FROM menu_items WHERE id = ?', [$id]);
            if ($it === null) {
                return;
            }
            $items = $this->items((string)$it['menu']);
            $ids = array_map('intval', array_column($items, 'id'));
            $i = array_search($id, $ids, true);
            $j = $i + ($direction < 0 ? -1 : 1);
            if ($i === false || !isset($ids[$j])) {
                return;
            }
            [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
            foreach ($ids as $pos => $iid) {
                $this->db->run('UPDATE menu_items SET position = ? WHERE id = ?', [$pos + 1, $iid]);
            }
        });
    }

    public static function validUrl(string $url): bool
    {
        if (strlen($url) > 500 || preg_match('/[\x00-\x20"<>\\\\]/', $url)) {
            return false;
        }
        return (str_starts_with($url, '/') && !str_starts_with($url, '//')) || (bool)preg_match('#^https://[a-z0-9.-]+(:\d+)?(/\S*)?$#i', $url);
    }
}
