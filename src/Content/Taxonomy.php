<?php
declare(strict_types=1);

namespace LuxAeterna\Content;

use LuxAeterna\Core\Database;
use LuxAeterna\Core\LuxException;

/** Categorías y etiquetas. Las entradas se asocian al contenido (no a cada revisión). */
final class Taxonomy
{
    public const KINDS = ['category' => 'Categoría', 'tag' => 'Etiqueta'];

    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(string $taxonomy): array
    {
        return $this->db->all('SELECT t.*, (SELECT COUNT(*) FROM content_terms ct JOIN contents c ON c.id = ct.content_id WHERE ct.term_id = t.id AND c.status = \'published\') AS used FROM terms t WHERE taxonomy = ? ORDER BY name COLLATE NOCASE', [$taxonomy]);
    }

    public function create(string $taxonomy, string $name): int
    {
        $name = trim($name);
        if (!isset(self::KINDS[$taxonomy]) || $name === '' || mb_strlen($name) > 80 || preg_match('/[\x00-\x1F]/', $name)) {
            throw new LuxException('El nombre debe tener entre 1 y 80 caracteres.');
        }
        $slug = Slug::normalize($name);
        if ($slug === '') {
            throw new LuxException('El nombre debe incluir letras o números.');
        }
        $ex = $this->db->one('SELECT id FROM terms WHERE taxonomy = ? AND slug = ?', [$taxonomy, $slug]);
        if ($ex !== null) {
            return (int)$ex['id'];
        }
        $this->db->run('INSERT INTO terms (uid, taxonomy, slug, name) VALUES (?, ?, ?, ?)', ['t_' . bin2hex(random_bytes(6)), $taxonomy, $slug, $name]);
        return $this->db->lastId();
    }

    public function delete(int $id): void
    {
        $this->db->run('DELETE FROM terms WHERE id = ?', [$id]);
    }

    /** @return list<int> */
    public function idsFor(int $contentId, string $taxonomy): array
    {
        return array_map('intval', array_column($this->db->all('SELECT t.id FROM content_terms ct JOIN terms t ON t.id = ct.term_id WHERE ct.content_id = ? AND t.taxonomy = ?', [$contentId, $taxonomy]), 'id'));
    }

    /**
     * Sustituye las categorías y etiquetas del contenido. Las etiquetas se indican por nombre (se crean si faltan).
     * @param list<int> $categoryIds @param list<string> $tagNames
     */
    public function assign(int $contentId, array $categoryIds, array $tagNames): void
    {
        $this->db->transaction(function () use ($contentId, $categoryIds, $tagNames): void {
            $ids = [];
            foreach ($categoryIds as $cid) {
                if ($this->db->val('SELECT 1 FROM terms WHERE id = ? AND taxonomy = \'category\'', [$cid])) {
                    $ids[] = $cid;
                }
            }
            if (count($tagNames) > 30) {
                throw new LuxException('Máximo 30 etiquetas por contenido.');
            }
            foreach ($tagNames as $n) {
                if (trim($n) !== '') {
                    $ids[] = $this->create('tag', $n);
                }
            }
            $this->db->run('DELETE FROM content_terms WHERE content_id = ?', [$contentId]);
            foreach (array_unique($ids) as $tid) {
                $this->db->run('INSERT INTO content_terms (content_id, term_id) VALUES (?, ?)', [$contentId, $tid]);
            }
        });
    }
}
