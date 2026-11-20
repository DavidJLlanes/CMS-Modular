<?php
declare(strict_types=1);

namespace CMSModular\Content;

use CMSModular\Core\Database;
use CMSModular\Core\LuxException;

/**
 * Contenido con revisiones. Reglas:
 *  - `current_rev` es la revisión en edición; `published_rev` la publicada. Guardar nunca toca lo publicado.
 *  - `version` es un contador de bloqueo optimista: todo guardado/restauración/publicación exige la versión leída.
 *  - Restaurar una revisión crea una revisión nueva (el historial no se reescribe).
 *  - Cambiar el slug de algo publicado registra una redirección 301 al publicar.
 *  - La papelera retira el contenido del sitio; restaurar lo devuelve a borrador.
 */
final class ContentRepository
{
    public const MAX_REVISIONS = 100;

    public function __construct(private readonly Database $db, private readonly TypeRegistry $types, private readonly Taxonomy $tax)
    {
    }

    /* ---------- lectura ---------- */

    /** @return array<string,mixed>|null contenido con la revisión en edición ('rev') y la publicada ('pub') */
    public function get(int $id): ?array
    {
        $c = $this->db->one('SELECT * FROM contents WHERE id = ?', [$id]);
        if ($c === null) {
            return null;
        }
        $c['rev'] = $this->rev((int)$c['current_rev']);
        $c['pub'] = $c['published_rev'] !== null ? $this->rev((int)$c['published_rev']) : null;
        $c['dirty'] = $c['published_rev'] !== null && (int)$c['published_rev'] !== (int)$c['current_rev'];
        return $c;
    }

    public function byUid(string $uid): ?array
    {
        $id = $this->db->val('SELECT id FROM contents WHERE uid = ?', [$uid]);
        return $id === null ? null : $this->get((int)$id);
    }

    /** @return list<array<string,mixed>> */
    public function search(?string $type = null, ?string $status = null, string $q = '', int $limit = 50, int $offset = 0): array
    {
        $sql = 'SELECT c.id, c.uid, c.type, c.status, c.slug, c.version, c.updated_at, c.published_rev, c.current_rev, r.title
                FROM contents c JOIN revisions r ON r.id = c.current_rev WHERE 1=1';
        $p = [];
        if ($type !== null && $type !== '') {
            $sql .= ' AND c.type = ?';
            $p[] = $type;
        }
        if ($status === 'all' || $status === null || $status === '') {
            $sql .= " AND c.status <> 'trashed'";
        } else {
            $sql .= ' AND c.status = ?';
            $p[] = $status;
        }
        if ($q !== '') {
            $sql .= " AND r.title LIKE ? ESCAPE '\\'";
            $p[] = '%' . addcslashes($q, '%_\\') . '%';
        }
        $sql .= ' ORDER BY c.updated_at DESC, c.id DESC LIMIT ' . max(1, min(200, $limit)) . ' OFFSET ' . max(0, $offset);
        return $this->db->all($sql, $p);
    }

    /** @return list<array<string,mixed>> */
    public function revisions(int $id): array
    {
        return $this->db->all('SELECT id, number, kind, title, slug, author_id, created_at FROM revisions WHERE content_id = ? AND number > 0 ORDER BY number DESC', [$id]);
    }

    public function revisionByNumber(int $id, int $number): ?array
    {
        $r = $this->db->one('SELECT * FROM revisions WHERE content_id = ? AND number = ?', [$id, $number]);
        return $r === null ? null : $this->decode($r);
    }

    /** Autoguardado más reciente que la revisión en edición (o null). */
    public function autosave(int $id): ?array
    {
        $a = $this->db->one('SELECT * FROM revisions WHERE content_id = ? AND number = 0', [$id]);
        $c = $this->db->one('SELECT r.created_at FROM contents c JOIN revisions r ON r.id = c.current_rev WHERE c.id = ?', [$id]);
        return $a !== null && $c !== null && (int)$a['created_at'] >= (int)$c['created_at'] ? $this->decode($a) : null;
    }

    /* ---------- escritura ---------- */

    /** @param array<string,mixed> $data title, slug?, summary?, fields?, blocks */
    public function create(string $type, array $data, int $authorId): int
    {
        if (!$this->types->has($type)) {
            throw new LuxException('Tipo de contenido desconocido.');
        }
        $d = $this->normalize($type, $data);
        return $this->db->transaction(function () use ($type, $d, $authorId): int {
            $this->checkSlugFree($type, $d['slug'], null);
            $now = time();
            $this->db->run('INSERT INTO contents (uid, type, status, slug, version, created_by, created_at, updated_at) VALUES (?, ?, \'draft\', ?, 1, ?, ?, ?)', ['c_' . bin2hex(random_bytes(6)), $type, $d['slug'], $authorId, $now, $now]);
            $id = $this->db->lastId();
            $rev = $this->insertRev($id, 1, 'save', $d, $authorId, $now);
            $this->db->run('UPDATE contents SET current_rev = ? WHERE id = ?', [$rev, $id]);
            return $id;
        });
    }

    /** @param array<string,mixed> $data @return int nueva versión */
    public function save(int $id, int $expectedVersion, array $data, int $authorId): int
    {
        return $this->db->transaction(function () use ($id, $expectedVersion, $data, $authorId): int {
            $c = $this->lockEditable($id, $expectedVersion);
            $d = $this->normalize((string)$c['type'], $data);
            $this->checkSlugFree((string)$c['type'], $d['slug'], $id);
            $cur = $this->rev((int)$c['current_rev']);
            $now = time();
            if ($this->same($cur, $d)) {
                $this->db->run('DELETE FROM revisions WHERE content_id = ? AND number = 0', [$id]);
                return (int)$c['version'];
            }
            $this->addRevision($id, 'save', $d, $authorId, $now);
            $this->db->run('DELETE FROM revisions WHERE content_id = ? AND number = 0', [$id]);
            return $this->bump($id, $d['slug'], $now);
        });
    }

    /** @param array<string,mixed> $data Guarda un autoguardado sin crear revisión ni cambiar la versión. */
    public function autosaveWrite(int $id, int $expectedVersion, array $data, int $authorId): void
    {
        $this->db->transaction(function () use ($id, $expectedVersion, $data, $authorId): void {
            $c = $this->lockEditable($id, $expectedVersion);
            $d = $this->normalize((string)$c['type'], $data, true);
            $this->db->run('DELETE FROM revisions WHERE content_id = ? AND number = 0', [$id]);
            $this->insertRev($id, 0, 'autosave', $d, $authorId, time());
        });
    }

    public function restoreRevision(int $id, int $number, int $expectedVersion, int $authorId): int
    {
        return $this->db->transaction(function () use ($id, $number, $expectedVersion, $authorId): int {
            $c = $this->lockEditable($id, $expectedVersion);
            $r = $this->revisionByNumber($id, $number);
            if ($r === null || $number < 1) {
                throw new LuxException('La revisión no existe.');
            }
            $d = ['title' => $r['title'], 'slug' => $r['slug'], 'summary' => $r['summary'], 'fields' => $r['fields'], 'blocks' => $r['blocks']];
            $this->checkSlugFree((string)$c['type'], $d['slug'], $id);
            $now = time();
            $this->addRevision($id, 'restore', $d, $authorId, $now);
            return $this->bump($id, $d['slug'], $now);
        });
    }

    public function publish(int $id, int $expectedVersion): int
    {
        return $this->db->transaction(function () use ($id, $expectedVersion): int {
            $c = $this->lock($id, $expectedVersion);
            if ($c['status'] === 'trashed') {
                throw new LuxException('Restaura el contenido de la papelera antes de publicarlo.');
            }
            $rev = $this->rev((int)$c['current_rev']);
            $type = (string)$c['type'];
            $this->types->validateFields($type, $rev['fields']);
            Blocks::validate($rev['blocks']);
            if ($type === 'page' && ($err = Slug::validate($rev['slug'], $this->types->reservedForPages())) !== null) {
                throw new LuxException($err);
            }
            $clash = $this->db->val("SELECT 1 FROM contents WHERE type = ? AND status = 'published' AND published_slug = ? AND id <> ?", [$type, $rev['slug'], $id]);
            if ($clash !== null) {
                throw new LuxException('Otra publicación ya usa esa ruta. Cambia el slug.');
            }
            $now = time();
            $new = $this->types->path($type, $rev['slug']);
            if ($c['published_slug'] !== null && $c['published_slug'] !== $rev['slug']) {
                $old = $this->types->path($type, (string)$c['published_slug']);
                $this->db->run('UPDATE redirects SET to_path = ? WHERE to_path = ?', [$new, $old]);
                $this->db->run('INSERT OR REPLACE INTO redirects (from_path, to_path, code, content_uid, created_at) VALUES (?, ?, 301, ?, ?)', [$old, $new, $c['uid'], $now]);
            }
            $this->db->run('DELETE FROM redirects WHERE from_path = ? OR to_path = from_path', [$new]);
            $this->db->run("UPDATE contents SET status = 'published', published_rev = ?, published_slug = ?, published_at = ?, updated_at = ?, version = version + 1 WHERE id = ?", [$c['current_rev'], $rev['slug'], $now, $now, $id]);
            return (int)$c['version'] + 1;
        });
    }

    public function unpublish(int $id, int $expectedVersion, ?string $protectedUid = null): int
    {
        return $this->db->transaction(function () use ($id, $expectedVersion, $protectedUid): int {
            $c = $this->lock($id, $expectedVersion);
            $this->refuseHome($c, $protectedUid);
            $this->db->run("UPDATE contents SET status = 'draft', published_rev = NULL, published_slug = NULL, updated_at = ?, version = version + 1 WHERE id = ?", [time(), $id]);
            return (int)$c['version'] + 1;
        });
    }

    public function trash(int $id, int $expectedVersion, ?string $protectedUid = null): int
    {
        return $this->db->transaction(function () use ($id, $expectedVersion, $protectedUid): int {
            $c = $this->lock($id, $expectedVersion);
            if ($c['status'] === 'trashed') {
                return (int)$c['version'];
            }
            $this->refuseHome($c, $protectedUid);
            $now = time();
            $this->db->run("UPDATE contents SET prev_status = status, status = 'trashed', published_rev = NULL, published_slug = NULL, trashed_at = ?, updated_at = ?, version = version + 1 WHERE id = ?", [$now, $now, $id]);
            return (int)$c['version'] + 1;
        });
    }

    public function restoreFromTrash(int $id, int $expectedVersion): int
    {
        return $this->db->transaction(function () use ($id, $expectedVersion): int {
            $c = $this->lock($id, $expectedVersion);
            if ($c['status'] !== 'trashed') {
                throw new LuxException('El contenido no está en la papelera.');
            }
            $this->checkSlugFree((string)$c['type'], (string)$c['slug'], $id, true);
            $this->db->run("UPDATE contents SET status = 'draft', prev_status = NULL, trashed_at = NULL, updated_at = ?, version = version + 1 WHERE id = ?", [time(), $id]);
            return (int)$c['version'] + 1;
        });
    }

    /** Borrado definitivo: solo desde la papelera. Elimina revisiones, relaciones, enlaces de menú y redirecciones propias. */
    public function deletePermanently(int $id): void
    {
        $this->db->transaction(function () use ($id): void {
            $c = $this->db->one('SELECT uid, status FROM contents WHERE id = ?', [$id]);
            if ($c === null || $c['status'] !== 'trashed') {
                throw new LuxException('Solo se puede borrar definitivamente lo que está en la papelera.');
            }
            $this->db->run('DELETE FROM menu_items WHERE target_uid = ?', [$c['uid']]);
            $this->db->run('DELETE FROM redirects WHERE content_uid = ?', [$c['uid']]);
            $this->db->run('DELETE FROM contents WHERE id = ?', [$id]);
        });
    }

    /* ---------- internos ---------- */

    /** @return array<string,mixed> contenido editable (no en papelera) con la versión comprobada */
    private function lockEditable(int $id, int $expectedVersion): array
    {
        $c = $this->lock($id, $expectedVersion);
        if ($c['status'] === 'trashed') {
            throw new LuxException('El contenido está en la papelera: restáuralo para editarlo.');
        }
        return $c;
    }

    /** @return array<string,mixed> contenido bloqueado y con la versión comprobada */
    private function lock(int $id, int $expectedVersion): array
    {
        $c = $this->db->one('SELECT * FROM contents WHERE id = ?', [$id]);
        if ($c === null) {
            throw new LuxException('El contenido no existe.');
        }
        if ((int)$c['version'] !== $expectedVersion) {
            throw new ConflictException('El contenido cambió mientras lo editabas (otra pestaña o persona). Tu texto no se ha perdido: cópialo y recarga el editor.');
        }
        return $c;
    }

    /** @param array<string,mixed> $c */
    private function refuseHome(array $c, ?string $protectedUid): void
    {
        if ($protectedUid !== null && $c['uid'] === $protectedUid) {
            throw new LuxException('Esta página es la portada del sitio. Elige otra portada antes de retirarla.');
        }
    }

    private function bump(int $id, string $slug, int $now): int
    {
        $this->db->run('UPDATE contents SET slug = ?, updated_at = ?, version = version + 1 WHERE id = ?', [$slug, $now, $id]);
        return (int)$this->db->val('SELECT version FROM contents WHERE id = ?', [$id]);
    }

    /** @param array<string,mixed> $d */
    private function addRevision(int $id, string $kind, array $d, int $authorId, int $now): int
    {
        $n = (int)$this->db->val('SELECT COALESCE(MAX(number), 0) + 1 FROM revisions WHERE content_id = ?', [$id]);
        $rev = $this->insertRev($id, $n, $kind, $d, $authorId, $now);
        $this->db->run('UPDATE contents SET current_rev = ? WHERE id = ?', [$rev, $id]);
        $this->pruneRevisions($id);
        return $rev;
    }

    /** Conserva la revisión publicada, la actual y las MAX_REVISIONS más recientes. */
    private function pruneRevisions(int $id): void
    {
        $this->db->run('DELETE FROM revisions WHERE content_id = ? AND number > 0 AND id NOT IN (
            SELECT id FROM revisions WHERE content_id = ? AND number > 0 ORDER BY number DESC LIMIT ' . self::MAX_REVISIONS . ')
            AND id NOT IN (SELECT COALESCE(published_rev, 0) FROM contents WHERE id = ?) AND id NOT IN (SELECT COALESCE(current_rev, 0) FROM contents WHERE id = ?)', [$id, $id, $id, $id]);
    }

    /** @param array<string,mixed> $d */
    private function insertRev(int $id, int $number, string $kind, array $d, int $authorId, int $now): int
    {
        $this->db->run('INSERT INTO revisions (content_id, number, kind, title, slug, summary, fields, blocks, author_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $id, $number, $kind, $d['title'], $d['slug'], $d['summary'],
            json_encode($d['fields'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), json_encode($d['blocks'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $authorId, $now,
        ]);
        return $this->db->lastId();
    }

    /** @return array<string,mixed> */
    private function rev(int $revId): array
    {
        return $this->decode($this->db->one('SELECT * FROM revisions WHERE id = ?', [$revId]) ?? throw new LuxException('Falta la revisión del contenido.'));
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private function decode(array $r): array
    {
        $r['fields'] = json_decode((string)$r['fields'], true) ?: [];
        $r['blocks'] = json_decode((string)$r['blocks'], true) ?: [];
        return $r;
    }

    /**
     * @param array<string,mixed> $data
     * @return array{title:string,slug:string,summary:string,fields:array<string,mixed>,blocks:list<array<string,mixed>>}
     */
    private function normalize(string $type, array $data, bool $lenient = false): array
    {
        $title = trim((string)($data['title'] ?? ''));
        if (mb_strlen($title) > 200 || preg_match('/[\x00-\x1F]/', $title)) {
            throw new LuxException('El título no es válido (máx. 200 caracteres).');
        }
        if ($title === '' && !$lenient) {
            throw new LuxException('El título es obligatorio.');
        }
        $slugIn = trim((string)($data['slug'] ?? ''));
        $slug = Slug::normalize($slugIn !== '' ? $slugIn : $title);
        if ($slug === '' && !$lenient) {
            throw new LuxException('No se pudo generar un slug: escribe uno con letras o números.');
        }
        if ($slug !== '' && $type === 'page' && ($err = Slug::validate($slug, $this->types->reservedForPages())) !== null && !$lenient) {
            throw new LuxException($err);
        }
        $summary = trim((string)($data['summary'] ?? ''));
        if (mb_strlen($summary) > 300 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $summary)) {
            throw new LuxException('El resumen no es válido (máx. 300 caracteres).');
        }
        $fields = $lenient ? (array)($data['fields'] ?? []) : $this->types->validateFields($type, (array)($data['fields'] ?? []));
        return ['title' => $title, 'slug' => $slug, 'summary' => $summary, 'fields' => $fields, 'blocks' => Blocks::validate($data['blocks'] ?? [])];
    }

    /** @param array<string,mixed> $rev @param array<string,mixed> $d */
    private function same(array $rev, array $d): bool
    {
        return $rev['title'] === $d['title'] && $rev['slug'] === $d['slug'] && $rev['summary'] === $d['summary']
            && json_encode($rev['fields']) === json_encode($d['fields']) && json_encode($rev['blocks']) === json_encode($d['blocks']);
    }

    private function checkSlugFree(string $type, string $slug, ?int $exceptId, bool $restoring = false): void
    {
        $q = "SELECT 1 FROM contents WHERE type = ? AND slug = ? AND status <> 'trashed'" . ($exceptId !== null ? ' AND id <> ' . (int)$exceptId : '');
        if ($this->db->val($q, [$type, $slug]) !== null) {
            throw new LuxException($restoring ? 'No se puede restaurar: otro contenido usa ahora ese slug. Edita el slug o borra el otro contenido.' : 'Ya existe otro contenido con ese slug. Elige uno distinto.');
        }
    }
}
