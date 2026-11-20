<?php
declare(strict_types=1);

namespace CMSModular\Gallery;

use CMSModular\Core\Database;
use CMSModular\Core\LuxException;
use CMSModular\Design\DesignRegistry;
use CMSModular\Design\FieldSchema;

/**
 * Galerías: conjuntos ordenados de imágenes de la biblioteca con un diseño por carpeta, recorte, foco por foto,
 * visor y disuasión. Todo valor se valida contra listas cerradas o contra el manifiesto del diseño.
 */
final class GalleryStore
{
    public const UID = '/^gal-[0-9a-f]{12}$/';
    public const MAX_ITEMS = 500;
    public const RATIO = ['square' => 1.0, 'landscape' => 1.5, 'portrait' => 0.8];
    public const CROP_LABEL = ['free' => 'Libre (sin recortar)', 'square' => 'Cuadrado', 'landscape' => 'Apaisado (3:2)', 'portrait' => 'Vertical (4:5)'];
    public const LB_VARIANTS = ['fade' => 'Fundido', 'slide' => 'Deslizar', 'zoom' => 'Zoom'];
    public const LB_DEFAULT = ['enabled' => true, 'variant' => 'fade', 'counter' => true, 'captions' => true, 'arrows' => true, 'loop' => true, 'preload' => true];
    public const PROTECT_DEFAULT = ['nodrag' => false, 'noctx' => false];

    /** @param \Closure():DesignRegistry $registry */
    public function __construct(private readonly Database $db, private readonly \Closure $registry)
    {
    }

    private function reg(): DesignRegistry
    {
        return ($this->registry)();
    }

    /** @return array<string,mixed>|null */
    public function get(string $uid): ?array
    {
        $g = $this->db->one('SELECT * FROM galleries WHERE uid = ?', [$uid]);
        return $g === null ? null : $this->decode($g);
    }

    /** @param array<string,mixed> $g @return array<string,mixed> */
    private function decode(array $g): array
    {
        $g['options'] = (array)json_decode((string)$g['options'], true);
        $g['lightbox'] = array_replace(self::LB_DEFAULT, (array)json_decode((string)$g['lightbox'], true));
        $g['protect'] = array_replace(self::PROTECT_DEFAULT, (array)json_decode((string)$g['protect'], true));
        return $g;
    }

    /** @return list<array<string,mixed>> con el número de fotos */
    public function all(string $q = ''): array
    {
        $where = '';
        $p = [];
        if (trim($q) !== '') {
            $where = " WHERE g.title LIKE ? ESCAPE '\\'";
            $p[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr(trim($q), 0, 80)) . '%';
        }
        return $this->db->all('SELECT g.*, (SELECT COUNT(*) FROM gallery_items i WHERE i.gallery_id = g.id) AS n FROM galleries g' . $where . ' ORDER BY g.updated_at DESC, g.id DESC LIMIT 200', $p);
    }

    /** @return array<string,mixed> */
    public function create(string $title, string $layout, ?int $userId = null): array
    {
        $d = $this->design($layout);
        $uid = 'gal-' . bin2hex(random_bytes(6));
        $now = time();
        $title = $this->cleanTitle($title);
        $this->db->run('INSERT INTO galleries (uid, title, layout, crop, options, lightbox, protect, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$uid, $title, $d['id'], $d['gallery']['crop_default'], json_encode($d['defaults'], JSON_UNESCAPED_UNICODE), json_encode(self::LB_DEFAULT), json_encode(self::PROTECT_DEFAULT), $userId, $now, $now]);
        return $this->get($uid) ?? throw new LuxException('No se pudo crear la galería.');
    }

    /** @return array<string,mixed> diseño de galería válido */
    public function design(string $id): array
    {
        $d = $this->reg()->get($id);
        if ($d === null || $d['type'] !== 'gallery') {
            throw new LuxException('El diseño de galería «' . substr($id, 0, 41) . '» no existe o no es válido.');
        }
        return $d;
    }

    private function cleanTitle(string $t): string
    {
        $t = trim(preg_replace('/\s+/u', ' ', $t) ?? '');
        if (mb_strlen($t) > 120) {
            throw new LuxException('El título admite como máximo 120 caracteres.');
        }
        return $t;
    }

    /**
     * Ajustes de la galería: título, diseño, recorte, opciones del diseño, visor y disuasión.
     * @param array<string,mixed> $in
     */
    public function update(string $uid, array $in): void
    {
        $g = $this->get($uid) ?? throw new LuxException('La galería no existe.');
        $layout = isset($in['layout']) ? (string)$in['layout'] : (string)$g['layout'];
        $d = $this->design($layout);
        $crop = isset($in['crop']) ? (string)$in['crop'] : (string)$g['crop'];
        if (!in_array($crop, $d['gallery']['crops'], true)) {
            $crop = $d['gallery']['crop_default']; // el diseño elegido no admite ese recorte: se usa el suyo por defecto
        }
        $opts = $layout === $g['layout'] ? (array)$g['options'] : $d['defaults'];
        if (isset($in['options']) && is_array($in['options'])) {
            try {
                $opts = array_replace($opts, FieldSchema::validate($d['fields'], $this->prepare($d['fields'], $in['options'])));
            } catch (\InvalidArgumentException $e) {
                throw new LuxException($e->getMessage());
            }
        }
        $opts = array_intersect_key($opts, $d['fields']);
        $lb = (array)$g['lightbox'];
        if (isset($in['lightbox']) && is_array($in['lightbox'])) {
            $lb = [
                'enabled' => !empty($in['lightbox']['enabled']),
                'variant' => array_key_exists((string)($in['lightbox']['variant'] ?? ''), self::LB_VARIANTS) ? (string)$in['lightbox']['variant'] : 'fade',
                'counter' => !empty($in['lightbox']['counter']), 'captions' => !empty($in['lightbox']['captions']), 'arrows' => !empty($in['lightbox']['arrows']),
                'loop' => !empty($in['lightbox']['loop']), 'preload' => !empty($in['lightbox']['preload']),
            ];
        }
        $pr = (array)$g['protect'];
        if (isset($in['protect']) && is_array($in['protect'])) {
            $pr = ['nodrag' => !empty($in['protect']['nodrag']), 'noctx' => !empty($in['protect']['noctx'])];
        }
        $title = array_key_exists('title', $in) ? $this->cleanTitle((string)$in['title']) : (string)$g['title'];
        $this->db->run('UPDATE galleries SET title = ?, layout = ?, crop = ?, options = ?, lightbox = ?, protect = ?, updated_at = ? WHERE uid = ?',
            [$title, $d['id'], $crop, json_encode($opts, JSON_UNESCAPED_UNICODE), json_encode($lb), json_encode($pr), time(), $uid]);
    }

    /** Casillas desmarcadas no se envían en un formulario: las booleanas ausentes valen falso. @param array<string,array<string,mixed>> $defs @param array<string,mixed> $v @return array<string,mixed> */
    private function prepare(array $defs, array $v): array
    {
        foreach ($defs as $n => $def) {
            if ($def['type'] === 'bool' && !array_key_exists($n, $v)) {
                $v[$n] = false;
            }
        }
        return $v;
    }

    /**
     * Reemplaza las fotos (orden incluido). Cada elemento: media (uid), y opcionalmente title, alt, caption, focus_x, focus_y, crop.
     * @param list<array<string,mixed>> $items
     */
    public function setItems(string $uid, array $items): void
    {
        $g = $this->get($uid) ?? throw new LuxException('La galería no existe.');
        if (count($items) > self::MAX_ITEMS) {
            throw new LuxException('Una galería admite como máximo ' . self::MAX_ITEMS . ' fotos.');
        }
        $rows = [];
        foreach (array_values($items) as $pos => $it) {
            $m = (string)($it['media'] ?? '');
            if (!preg_match('/^img-[0-9a-f]{12}$/', $m) || $this->db->val('SELECT COUNT(*) FROM media WHERE uid = ?', [$m]) === 0) {
                throw new LuxException('La foto ' . ($pos + 1) . ' no existe en la biblioteca.');
            }
            $row = ['media_uid' => $m];
            foreach (['title' => 120, 'alt' => 300, 'caption' => 500] as $k => $max) {
                $v = array_key_exists($k, $it) && $it[$k] !== null ? trim(preg_replace('/\s+/u', ' ', (string)$it[$k]) ?? '') : '';
                if (mb_strlen($v) > $max) {
                    throw new LuxException("Foto " . ($pos + 1) . ": «{$k}» admite como máximo $max caracteres.");
                }
                $row[$k] = $v === '' ? null : $v;
            }
            foreach (['focus_x', 'focus_y'] as $k) {
                $v = $it[$k] ?? null;
                if ($v === null || $v === '') {
                    $row[$k] = null;
                    continue;
                }
                $n = filter_var($v, FILTER_VALIDATE_INT);
                if ($n === false || $n < 0 || $n > 100) {
                    throw new LuxException('Foto ' . ($pos + 1) . ': el foco debe estar entre 0 y 100.');
                }
                $row[$k] = $n;
            }
            if (($row['focus_x'] === null) !== ($row['focus_y'] === null)) {
                $row['focus_x'] ??= 50;
                $row['focus_y'] ??= 50;
            }
            $c = $it['crop'] ?? null;
            $row['crop'] = $c === null || $c === '' ? null : (in_array($c, DesignRegistry::CROPS, true) ? (string)$c : throw new LuxException('Foto ' . ($pos + 1) . ': recorte no válido.'));
            $rows[] = $row;
        }
        $this->db->transaction(function () use ($g, $rows, $uid): void {
            $this->db->run('DELETE FROM gallery_items WHERE gallery_id = ?', [$g['id']]);
            foreach ($rows as $pos => $r) {
                $this->db->run('INSERT INTO gallery_items (gallery_id, media_uid, position, title, alt, caption, focus_x, focus_y, crop) VALUES (?,?,?,?,?,?,?,?,?)',
                    [$g['id'], $r['media_uid'], $pos, $r['title'], $r['alt'], $r['caption'], $r['focus_x'], $r['focus_y'], $r['crop']]);
            }
            $this->db->run('UPDATE galleries SET updated_at = ? WHERE uid = ?', [time(), $uid]);
        });
    }

    /** Añade fotos al final (sin duplicar las que ya están). @param list<string> $mediaUids */
    public function addMedia(string $uid, array $mediaUids): int
    {
        $cur = $this->items($uid);
        $have = array_column($cur, 'media_uid');
        $items = array_map(static fn(array $i): array => ['media' => $i['media_uid'], 'title' => $i['title'], 'alt' => $i['alt'], 'caption' => $i['caption'], 'focus_x' => $i['focus_x'], 'focus_y' => $i['focus_y'], 'crop' => $i['crop']], $cur);
        $n = 0;
        foreach ($mediaUids as $m) {
            if (!in_array($m, $have, true)) {
                $items[] = ['media' => $m];
                $have[] = $m;
                $n++;
            }
        }
        $this->setItems($uid, $items);
        return $n;
    }

    /** @return list<array<string,mixed>> fotos en orden, con los datos de la biblioteca (prefijo m_) */
    public function items(string $uid): array
    {
        return $this->db->all('SELECT i.*, m.title AS m_title, m.alt AS m_alt, m.caption AS m_caption, m.credit AS m_credit, m.focus_x AS m_fx, m.focus_y AS m_fy, m.width AS m_w, m.height AS m_h, m.sha256 AS m_sha
            FROM gallery_items i JOIN galleries g ON g.id = i.gallery_id LEFT JOIN media m ON m.uid = i.media_uid WHERE g.uid = ? ORDER BY i.position', [$uid]);
    }

    /** Contenidos que usan la galería (revisión en edición o publicada). @return list<array{uid:string,type:string,title:string,published:bool,editing:bool}> */
    public function references(string $uid): array
    {
        $rows = $this->db->all('SELECT c.uid, c.type, c.current_rev, c.published_rev, r.id AS rid, r.title, r.blocks FROM contents c
            JOIN revisions r ON r.id IN (c.current_rev, c.published_rev) WHERE c.status <> \'trashed\' AND r.blocks LIKE ?', ['%"ref":"' . $uid . '"%']);
        $out = [];
        foreach ($rows as $r) {
            $found = false;
            \CMSModular\Content\Blocks::walk((array)json_decode((string)$r['blocks'], true), static function (array $b) use ($uid, &$found): void {
                $found = $found || (($b['type'] ?? '') === 'gallery' && ($b['ref'] ?? '') === $uid);
            });
            if (!$found) {
                continue;
            }
            $k = (string)$r['uid'];
            $out[$k] ??= ['uid' => $k, 'type' => (string)$r['type'], 'title' => (string)$r['title'], 'published' => false, 'editing' => false];
            $out[$k]['published'] = $out[$k]['published'] || (int)$r['rid'] === (int)$r['published_rev'];
            $out[$k]['editing'] = $out[$k]['editing'] || (int)$r['rid'] === (int)$r['current_rev'];
        }
        return array_values($out);
    }

    public function delete(string $uid): void
    {
        $this->get($uid) ?? throw new LuxException('La galería no existe.');
        $refs = $this->references($uid);
        if ($refs !== []) {
            throw new LuxException('No se puede borrar: la usan ' . count($refs) . ' contenido(s) (por ejemplo «' . $refs[0]['title'] . '»). Quita el bloque de galería de esos contenidos.');
        }
        $this->db->run('DELETE FROM galleries WHERE uid = ?', [$uid]);
    }

    /** Galerías que usan una imagen (para impedir su borrado). @return list<array{uid:string,title:string}> */
    public function usingMedia(string $mediaUid): array
    {
        return $this->db->all('SELECT DISTINCT g.uid, g.title FROM galleries g JOIN gallery_items i ON i.gallery_id = g.id WHERE i.media_uid = ?', [$mediaUid]);
    }

    /**
     * Congela, para la publicación o la vista previa, las galerías indicadas con sus fotos y los registros de imagen que usan.
     * @param list<string> $uids @return array{galleries:array<string,array<string,mixed>>,media:array<string,array<string,mixed>>}
     */
    public function snapshot(array $uids): array
    {
        $out = ['galleries' => [], 'media' => []];
        foreach (array_unique($uids) as $uid) {
            $g = $this->get($uid);
            if ($g === null) {
                continue;
            }
            $items = [];
            $all = $this->items($uid);
            foreach ($all as $i) {
                if ($i['m_sha'] === null) {
                    continue; // imagen borrada: se avisa al publicar
                }
                $items[] = ['media' => $i['media_uid'], 'title' => $i['title'] ?? $i['m_title'], 'alt' => $i['alt'] ?? $i['m_alt'], 'caption' => $i['caption'] ?? $i['m_caption'], 'credit' => $i['m_credit'],
                    'fx' => (int)($i['focus_x'] ?? $i['m_fx']), 'fy' => (int)($i['focus_y'] ?? $i['m_fy']), 'crop' => $i['crop'], 'w' => (int)$i['m_w'], 'h' => (int)$i['m_h'], 'sha' => $i['m_sha'],
                    'missing_alt' => trim((string)($i['alt'] ?? $i['m_alt'])) === ''];
                $out['media'][$i['media_uid']] = null;
            }
            $out['galleries'][$uid] = ['uid' => $uid, 'title' => $g['title'], 'layout' => $g['layout'], 'crop' => $g['crop'], 'options' => $g['options'], 'lightbox' => $g['lightbox'], 'protect' => $g['protect'], 'items' => $items,
                'dangling' => count($all) - count($items)];
        }
        if ($out['media'] !== []) {
            $marks = implode(',', array_fill(0, count($out['media']), '?'));
            $out['media'] = [];
            $want = [];
            foreach ($out['galleries'] as $g) {
                foreach ($g['items'] as $i) {
                    $want[(string)$i['media']] = true;
                }
            }
            foreach ($this->db->all("SELECT * FROM media WHERE uid IN ($marks)", array_keys($want)) as $m) {
                $out['media'][(string)$m['uid']] = $m;
            }
        }
        return $out;
    }
}
