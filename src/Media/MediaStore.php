<?php
declare(strict_types=1);

namespace CMSModular\Media;

use CMSModular\Core\Database;
use CMSModular\Core\Fs;
use CMSModular\Core\LuxException;

/**
 * Biblioteca multimedia. Los originales se guardan FUERA de la web con identificadores generados (nunca con el
 * nombre subido) y no se modifican jamás. Los derivados WebP se identifican por huella del original + receta.
 */
final class MediaStore
{
    public const THUMB = 360;

    /** @param \Closure():MediaSettings $settings */
    public function __construct(private readonly Database $db, private readonly string $base, private readonly \Closure $settings)
    {
    }

    public function settings(): MediaSettings
    {
        return ($this->settings)();
    }

    public function originalPath(array $m): string
    {
        return $this->base . '/originals/' . substr((string)$m['uid'], 4, 2) . '/' . $m['uid'] . '.' . $m['ext'];
    }

    public function derivedPath(string $key): string
    {
        return $this->base . '/derived/' . $key . '.webp';
    }

    /** @return array<string,mixed>|null */
    public function get(string $uid): ?array
    {
        return $this->db->one('SELECT * FROM media WHERE uid = ?', [$uid]);
    }

    /** @return array{items:list<array<string,mixed>>,total:int} */
    public function search(string $q = '', int $limit = 24, int $offset = 0): array
    {
        $where = '';
        $p = [];
        if (trim($q) !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr(trim($q), 0, 80)) . '%';
            $where = " WHERE title LIKE ? ESCAPE '\\' OR alt LIKE ? ESCAPE '\\' OR caption LIKE ? ESCAPE '\\' OR credit LIKE ? ESCAPE '\\' OR uid LIKE ? ESCAPE '\\'";
            $p = [$like, $like, $like, $like, $like];
        }
        $total = (int)$this->db->val('SELECT COUNT(*) FROM media' . $where, $p);
        $items = $this->db->all('SELECT * FROM media' . $where . ' ORDER BY created_at DESC, id DESC LIMIT ' . max(1, min(100, $limit)) . ' OFFSET ' . max(0, $offset), $p);
        return ['items' => $items, 'total' => $total];
    }

    /**
     * Sube un archivo nuevo. Si el mismo contenido ya existe devuelve el registro existente.
     * @return array<string,mixed> registro más la clave `duplicate`
     */
    public function add(string $tmp, string $name, ?int $userId = null): array
    {
        $s = $this->settings();
        $i = ImageInspector::inspect($tmp, $s);
        $sha = hash_file('sha256', $tmp);
        $dup = $this->db->one('SELECT * FROM media WHERE sha256 = ?', [$sha]);
        if ($dup !== null) {
            return $dup + ['duplicate' => true];
        }
        $uid = 'img-' . bin2hex(random_bytes(6));
        $now = time();
        $row = [
            'uid' => $uid, 'title' => self::titleFrom($name), 'mime' => $i['mime'], 'ext' => $i['ext'], 'bytes' => $i['bytes'], 'width' => $i['width'],
            'height' => $i['height'], 'sha256' => $sha, 'has_alpha' => $i['has_alpha'] ? 1 : 0, 'notes' => implode(' ', $i['notes']),
        ];
        $dest = $this->originalPath($row);
        $this->store($tmp, $dest);
        try {
            $this->db->run('INSERT INTO media (uid,title,mime,ext,bytes,width,height,sha256,has_alpha,notes,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', [
                $uid, $row['title'], $row['mime'], $row['ext'], $row['bytes'], $row['width'], $row['height'], $sha, $row['has_alpha'], $row['notes'], $userId, $now, $now,
            ]);
            $m = (array)$this->get($uid);
            $this->ensure($m + ['orientation' => $i['orientation']], [$this->thumbRecipe()]); // decodifica: valida que el archivo no esté dañado
        } catch (\Throwable $e) {
            $this->db->run('DELETE FROM media WHERE uid = ?', [$uid]);
            @unlink($dest);
            throw $e;
        }
        return $m + ['duplicate' => false];
    }

    /** Sustituye el archivo conservando identificador, textos y referencias. @return array<string,mixed> */
    public function replace(string $uid, string $tmp): array
    {
        $m = $this->get($uid) ?? throw new LuxException('La imagen no existe.');
        $i = ImageInspector::inspect($tmp, $this->settings());
        $sha = hash_file('sha256', $tmp);
        $other = $this->db->one('SELECT uid FROM media WHERE sha256 = ? AND uid <> ?', [$sha, $uid]);
        if ($other !== null) {
            throw new LuxException('Ese archivo ya está en la biblioteca como «' . $other['uid'] . '».');
        }
        $new = ['uid' => $uid, 'ext' => $i['ext']] + $m;
        $dest = $this->originalPath($new);
        $old = $this->originalPath($m);
        $this->store($tmp, $dest);
        $now = time();
        try {
            $this->db->run('UPDATE media SET mime=?, ext=?, bytes=?, width=?, height=?, sha256=?, has_alpha=?, notes=?, updated_at=? WHERE uid=?',
                [$i['mime'], $i['ext'], $i['bytes'], $i['width'], $i['height'], $sha, $i['has_alpha'] ? 1 : 0, implode(' ', $i['notes']), $now, $uid]);
            $m2 = (array)$this->get($uid);
            $this->ensure($m2 + ['orientation' => $i['orientation']], [$this->thumbRecipe()]);
        } catch (\Throwable $e) {
            $this->db->run('UPDATE media SET mime=?, ext=?, bytes=?, width=?, height=?, sha256=?, has_alpha=?, notes=?, updated_at=? WHERE uid=?',
                [$m['mime'], $m['ext'], $m['bytes'], $m['width'], $m['height'], $m['sha256'], $m['has_alpha'], $m['notes'], $m['updated_at'], $uid]);
            if ($dest !== $old) {
                @unlink($dest);
            }
            throw $e;
        }
        if ($dest !== $old) {
            @unlink($old);
        }
        return $m2;
    }

    /** @param array<string,mixed> $in */
    public function update(string $uid, array $in): void
    {
        $m = $this->get($uid) ?? throw new LuxException('La imagen no existe.');
        $lim = ['title' => 120, 'alt' => 300, 'caption' => 500, 'credit' => 200];
        $set = [];
        $p = [];
        foreach ($lim as $k => $max) {
            if (array_key_exists($k, $in)) {
                $v = trim(preg_replace('/\s+/u', ' ', (string)$in[$k]) ?? '');
                if (mb_strlen($v) > $max) {
                    throw new LuxException("El campo «$k» admite como máximo $max caracteres.");
                }
                $set[] = "$k = ?";
                $p[] = $v;
            }
        }
        foreach (['focus_x', 'focus_y'] as $k) {
            if (array_key_exists($k, $in)) {
                $v = filter_var($in[$k], FILTER_VALIDATE_INT);
                if ($v === false || $v < 0 || $v > 100) {
                    throw new LuxException('El foco debe ser un número entre 0 y 100.');
                }
                $set[] = "$k = ?";
                $p[] = $v;
            }
        }
        if ($set !== []) {
            $this->db->run('UPDATE media SET ' . implode(', ', $set) . ', updated_at = ? WHERE uid = ?', [...$p, time(), $m['uid']]);
        }
    }

    /**
     * Contenidos que usan la imagen (revisión en edición o publicada; la papelera no cuenta).
     * @return list<array{uid:string,type:string,title:string,published:bool,editing:bool}>
     */
    public function references(string $uid): array
    {
        $rows = $this->db->all('SELECT c.uid, c.type, c.current_rev, c.published_rev, r.id AS rid, r.title, r.blocks FROM contents c
            JOIN revisions r ON r.id IN (c.current_rev, c.published_rev) WHERE c.status <> \'trashed\' AND r.blocks LIKE ?', ['%"ref":"' . $uid . '"%']);
        $out = [];
        foreach ($rows as $r) {
            $has = false;
            foreach ((array)json_decode((string)$r['blocks'], true) as $b) {
                if (is_array($b) && ($b['type'] ?? '') === 'image' && ($b['ref'] ?? '') === $uid) {
                    $has = true;
                }
            }
            if (!$has) {
                continue;
            }
            $k = (string)$r['uid'];
            $out[$k] ??= ['uid' => $k, 'type' => (string)$r['type'], 'title' => (string)$r['title'], 'published' => false, 'editing' => false];
            $out[$k]['published'] = $out[$k]['published'] || (int)$r['rid'] === (int)$r['published_rev'];
            $out[$k]['editing'] = $out[$k]['editing'] || (int)$r['rid'] === (int)$r['current_rev'];
        }
        return array_values($out);
    }

    /** Borra la imagen y sus derivados. Se niega si algún contenido la usa. */
    public function delete(string $uid): void
    {
        $m = $this->get($uid) ?? throw new LuxException('La imagen no existe.');
        $gal = (int)$this->db->val('SELECT COUNT(DISTINCT gallery_id) FROM gallery_items WHERE media_uid = ?', [$uid]);
        if ($gal > 0) {
            throw new LuxException('No se puede borrar: forma parte de ' . $gal . ' galería(s). Quítala de ellas primero.');
        }
        $refs = $this->references($uid);
        if ($refs !== []) {
            throw new LuxException('No se puede borrar: la usan ' . count($refs) . ' contenido(s) (por ejemplo «' . $refs[0]['title'] . '»). Quita la imagen de esos contenidos o sustitúyela.');
        }
        foreach ($this->db->all('SELECT key FROM media_derivs WHERE media_id = ?', [$m['id']]) as $d) {
            @unlink($this->derivedPath((string)$d['key']));
        }
        $this->db->run('DELETE FROM media WHERE id = ?', [$m['id']]);
        @unlink($this->originalPath($m));
    }

    /** @return array{w:int,h:int,fit:string,fx:int,fy:int,q:int,v:int} */
    public function thumbRecipe(): array
    {
        return Recipe::make(self::THUMB, 0, 'inside', 50, 50, 70);
    }

    /**
     * Garantiza que existen los derivados de las recetas pedidas; construye solo los que faltan, con una sola
     * decodificación y un único trabajador (bloqueo). @param array<string,mixed> $m registro (+ `orientation` si se conoce)
     * @param list<array{w:int,h:int,fit:string,fx:int,fy:int,q:int,v:int}> $recipes
     * @return array<string,array{file:string,width:int,height:int,bytes:int}> por clave
     */
    public function ensure(array $m, array $recipes): array
    {
        $res = [];
        $todo = [];
        foreach ($recipes as $r) {
            $key = Recipe::key((string)$m['sha256'], $r);
            $row = $this->db->one('SELECT width, height, bytes, last_used_at FROM media_derivs WHERE key = ?', [$key]);
            $file = $this->derivedPath($key);
            if ($row !== null && is_file($file)) {
                $res[$key] = ['file' => $file, 'width' => (int)$row['width'], 'height' => (int)$row['height'], 'bytes' => (int)$row['bytes']];
                if ((int)$row['last_used_at'] < time() - 3600) {
                    $this->db->run('UPDATE media_derivs SET last_used_at = ? WHERE key = ?', [time(), $key]);
                }
                continue;
            }
            $todo[$key] = $r;
        }
        if ($todo === []) {
            return $res;
        }
        Fs::ensureDir($this->base . '/derived');
        $lock = fopen($this->base . '/.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) { // un solo trabajador de imágenes a la vez
            throw new LuxException('No se pudo reservar el procesador de imágenes.');
        }
        try {
            @set_time_limit($this->settings()->maxSeconds());
            foreach ($todo as $key => $r) { // otro proceso pudo construirlo mientras esperábamos
                if (is_file($this->derivedPath($key)) && $this->db->val('SELECT 1 FROM media_derivs WHERE key = ?', [$key]) !== null) {
                    unset($todo[$key]);
                }
            }
            if ($todo !== []) {
                $out = DerivativeBuilder::render($this->originalPath($m), (string)$m['mime'], (int)($m['orientation'] ?? $this->orientationOf($m)), array_values($todo));
                $keys = array_keys($todo);
                foreach ($out as $n => $o) {
                    $key = $keys[$n];
                    Fs::atomicWrite($this->derivedPath($key), $o['data'], 0640);
                    $this->db->run('INSERT OR REPLACE INTO media_derivs (key, media_id, orig_sha, recipe, width, height, bytes, created_at, last_used_at) VALUES (?,?,?,?,?,?,?,?,?)',
                        [$key, $m['id'], $m['sha256'], json_encode($o['recipe']), $o['width'], $o['height'], strlen($o['data']), time(), time()]);
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $this->ensure($m, $recipes);
    }

    /** Orientación EXIF del original (solo JPEG). */
    private function orientationOf(array $m): int
    {
        if ($m['mime'] !== 'image/jpeg' || !function_exists('exif_read_data')) {
            return 1;
        }
        $ex = @exif_read_data($this->originalPath($m), 'IFD0');
        return is_array($ex) ? max(1, min(8, (int)($ex['Orientation'] ?? 1))) : 1;
    }

    /**
     * Limpia derivados que ningún trabajo necesita: se conservan los de $keep (versiones retenidas y uso actual)
     * y los usados hace menos de $graceDays; los de un original sustituido caducan a las 24 h.
     * @param list<string> $keep claves a conservar @return array{removed:int,bytes:int}
     */
    public function gc(array $keep, int $graceDays = 7, bool $dry = false): array
    {
        $keepSet = array_flip($keep);
        $n = 0;
        $bytes = 0;
        foreach ($this->db->all('SELECT d.key, d.bytes, d.last_used_at, d.orig_sha, m.sha256 FROM media_derivs d JOIN media m ON m.id = d.media_id') as $d) {
            if (isset($keepSet[$d['key']])) {
                continue;
            }
            $stale = $d['orig_sha'] !== $d['sha256'];
            $age = time() - (int)$d['last_used_at'];
            if (($stale && $age > 86400) || (!$stale && $age > $graceDays * 86400)) {
                $n++;
                $bytes += (int)$d['bytes'];
                if (!$dry) {
                    @unlink($this->derivedPath((string)$d['key']));
                    $this->db->run('DELETE FROM media_derivs WHERE key = ?', [$d['key']]);
                }
            }
        }
        return ['removed' => $n, 'bytes' => $bytes];
    }

    /**
     * Comprueba la integridad de los originales (backup/restauración): faltan, están alterados o sobran archivos.
     * @return array{checked:int,missing:list<string>,corrupt:list<string>,orphans:list<string>}
     */
    public function verify(): array
    {
        $out = ['checked' => 0, 'missing' => [], 'corrupt' => [], 'orphans' => []];
        $known = [];
        foreach ($this->db->all('SELECT * FROM media ORDER BY id') as $m) {
            $out['checked']++;
            $f = $this->originalPath($m);
            $known[$f] = true;
            if (!is_file($f)) {
                $out['missing'][] = (string)$m['uid'];
            } elseif (hash_file('sha256', $f) !== $m['sha256']) {
                $out['corrupt'][] = (string)$m['uid'];
            }
        }
        $dir = $this->base . '/originals';
        if (is_dir($dir)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
                if (!isset($known[$f->getPathname()])) {
                    $out['orphans'][] = substr($f->getPathname(), strlen($this->base) + 1);
                }
            }
        }
        return $out;
    }

    /** @return array{count:int,original_bytes:int,derived_count:int,derived_bytes:int} */
    public function stats(): array
    {
        return [
            'count' => (int)$this->db->val('SELECT COUNT(*) FROM media'), 'original_bytes' => (int)$this->db->val('SELECT COALESCE(SUM(bytes),0) FROM media'),
            'derived_count' => (int)$this->db->val('SELECT COUNT(*) FROM media_derivs'), 'derived_bytes' => (int)$this->db->val('SELECT COALESCE(SUM(bytes),0) FROM media_derivs'),
        ];
    }

    private function store(string $tmp, string $dest): void
    {
        Fs::ensureDir(dirname($dest));
        $part = $dest . '.part-' . bin2hex(random_bytes(3));
        if (!@copy($tmp, $part)) {
            throw new LuxException('No se pudo guardar el original.', "copy $tmp -> $part");
        }
        @chmod($part, 0640);
        if (!@rename($part, $dest)) {
            @unlink($part);
            throw new LuxException('No se pudo guardar el original.', "rename $part -> $dest");
        }
    }

    private static function titleFrom(string $name): string
    {
        $base = pathinfo(str_replace('\\', '/', $name), PATHINFO_FILENAME);
        $t = trim((string)preg_replace('/[\x00-\x1F\x7F]+|[-_]+|\s+/u', ' ', $base));
        return mb_substr($t === '' ? 'Imagen' : $t, 0, 120);
    }
}
