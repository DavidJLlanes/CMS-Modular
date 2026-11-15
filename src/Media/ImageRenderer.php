<?php
declare(strict_types=1);

namespace LuxAeterna\Media;

use LuxAeterna\Core\LuxException;

/**
 * Convierte bloques de imagen en HTML responsive (srcset, sizes, width/height, carga diferida y prioridad para el LCP)
 * y lleva la cuenta de los derivados que la publicación debe escribir. En previsualización apunta a rutas privadas del
 * panel y no construye nada por adelantado: el panel genera cada derivado al pedirlo.
 */
final class ImageRenderer
{
    public const PUBLIC_DIR = '/media/';
    public const PREVIEW_DIR = '/admin/preview/media/';

    /** @var array<string,array{rec:array<string,mixed>,recipes:array<string,array<string,int|string>>}> por uid */
    private array $used = [];
    /** @var array<string,string> url pública → clave */
    private array $urls = [];

    /**
     * @param array<string,array<string,mixed>> $items registros de media por uid
     * @param int $displayPx ancho máximo con el que se muestra la imagen (ancho de contenido del tema)
     */
    public function __construct(private readonly array $items, private readonly int $quality, private readonly int $maxWidth, private readonly int $displayPx, private readonly ?MediaStore $store, private readonly bool $preview = false)
    {
    }

    /** @param array{ref:string,alt?:string,caption?:string} $b */
    public function figure(array $b, bool $priority = false): string
    {
        $rec = $this->items[$b['ref']] ?? null;
        if ($rec === null) {
            if ($this->preview) {
                return '<p class="lux-missing">[Imagen «' . self::e((string)$b['ref']) . '» no encontrada en la biblioteca]</p>' . "\n";
            }
            throw new LuxException('Una imagen usada en el contenido ya no existe en la biblioteca (' . $b['ref'] . ').');
        }
        $variants = $this->plan($rec);
        $alt = trim((string)($b['alt'] ?? '')) !== '' ? trim((string)$b['alt']) : (string)$rec['alt'];
        $caption = trim((string)($b['caption'] ?? '')) !== '' ? trim((string)$b['caption']) : (string)$rec['caption'];
        $src = $variants[array_key_last($variants)];
        foreach ($variants as $v) {
            if ($v['width'] >= $this->displayPx) {
                $src = $v;
                break;
            }
        }
        $img = '<img src="' . self::e($src['url']) . '"';
        if (count($variants) > 1) {
            $img .= ' srcset="' . self::e(implode(', ', array_map(static fn(array $v): string => $v['url'] . ' ' . $v['width'] . 'w', $variants))) . '"'
                . ' sizes="(min-width: ' . $this->displayPx . 'px) ' . $this->displayPx . 'px, 100vw"';
        }
        $img .= ' width="' . $src['width'] . '" height="' . $src['height'] . '" alt="' . self::e($alt) . '"';
        $img .= $priority ? ' fetchpriority="high" decoding="async">' : ' loading="lazy" decoding="async">';
        $credit = trim((string)$rec['credit']);
        $cap = ($caption !== '' ? self::e($caption) : '') . ($credit !== '' ? ($caption !== '' ? ' ' : '') . '<small>' . self::e($credit) . '</small>' : '');
        return '<figure class="lux-img">' . $img . ($cap !== '' ? '<figcaption>' . $cap . '</figcaption>' : '') . "</figure>\n";
    }

    /** @param array<string,mixed> $rec @return list<array{url:string,width:int,height:int}> de menor a mayor */
    public function plan(array $rec, ?int $displayPx = null): array
    {
        $out = [];
        foreach (Recipe::widths((int)$rec['width'], $this->maxWidth, $displayPx ?? $this->displayPx) as $w) {
            $r = Recipe::make($w, 0, 'inside', 50, 50, $this->quality);
            $key = Recipe::key((string)$rec['sha256'], $r);
            [$rw, $rh] = Recipe::dims($r, (int)$rec['width'], (int)$rec['height']);
            $url = ($this->preview ? self::PREVIEW_DIR : self::PUBLIC_DIR) . $rec['uid'] . '-' . $w . '.' . $key . '.webp';
            $out[] = ['url' => $url, 'width' => $rw, 'height' => $rh];
            $this->used[(string)$rec['uid']]['rec'] = $rec;
            $this->used[(string)$rec['uid']]['recipes'][$key] = $r;
            $this->urls[$url] = $key;
        }
        return $out;
    }

    /**
     * Variantes en las anchuras pedidas (acotadas al original y al máximo configurado); para el visor de galerías.
     * @param array<string,mixed> $rec @param list<int> $widths @return list<array{url:string,width:int,height:int}>
     */
    public function planWidths(array $rec, array $widths): array
    {
        $cap = max(16, min((int)$rec['width'], $this->maxWidth));
        $ws = array_values(array_unique(array_map(static fn(int $w): int => min($w, $cap), $widths)));
        sort($ws);
        $out = [];
        foreach ($ws as $w) {
            $r = Recipe::make($w, 0, 'inside', 50, 50, $this->quality);
            $key = Recipe::key((string)$rec['sha256'], $r);
            [$rw, $rh] = Recipe::dims($r, (int)$rec['width'], (int)$rec['height']);
            $url = ($this->preview ? self::PREVIEW_DIR : self::PUBLIC_DIR) . $rec['uid'] . '-' . $w . '.' . $key . '.webp';
            $out[] = ['url' => $url, 'width' => $rw, 'height' => $rh];
            $this->used[(string)$rec['uid']]['rec'] = $rec;
            $this->used[(string)$rec['uid']]['recipes'][$key] = $r;
            $this->urls[$url] = $key;
        }
        return $out;
    }

    /**
     * Variantes recortadas (proporción fija, centradas en el foco) para miniaturas de galería. Nunca amplía el original.
     * @param array<string,mixed> $rec @return list<array{url:string,width:int,height:int}> de menor a mayor
     */
    public function planCover(array $rec, int $displayPx, float $ratio, int $fx, int $fy): array
    {
        $out = [];
        $seen = [];
        foreach (Recipe::widths((int)$rec['width'], $this->maxWidth, $displayPx) as $w) {
            $h = max(16, (int)round($w / $ratio));
            // Un original más pequeño que la receta daría un recorte menor que lo pedido: se acota con las dimensiones reales.
            $r = Recipe::make($w, $h, 'cover', $fx, $fy, $this->quality);
            [$rw, $rh] = Recipe::dims($r, (int)$rec['width'], (int)$rec['height']);
            if (isset($seen[$rw])) {
                continue;
            }
            $seen[$rw] = true;
            $key = Recipe::key((string)$rec['sha256'], $r);
            $url = ($this->preview ? self::PREVIEW_DIR : self::PUBLIC_DIR) . $rec['uid'] . '-' . $w . 'x' . $h . 'f' . $r['fx'] . '-' . $r['fy'] . '.' . $key . '.webp';
            $out[] = ['url' => $url, 'width' => $rw, 'height' => $rh];
            $this->used[(string)$rec['uid']]['rec'] = $rec;
            $this->used[(string)$rec['uid']]['recipes'][$key] = $r;
            $this->urls[$url] = $key;
        }
        return $out;
    }

    /**
     * Construye los derivados que faltan y devuelve url pública → archivo, listo para copiar a la versión.
     * @return array<string,string>
     */
    public function files(): array
    {
        if ($this->used === []) {
            return [];
        }
        if ($this->store === null) {
            throw new LuxException('Hay imágenes que publicar pero la biblioteca multimedia no está disponible.');
        }
        $byKey = [];
        foreach ($this->used as $u) {
            foreach ($this->store->ensure($u['rec'], array_values($u['recipes'])) as $key => $d) {
                $byKey[$key] = $d['file'];
            }
        }
        $out = [];
        foreach ($this->urls as $url => $key) {
            $out[$url] = $byKey[$key];
        }
        ksort($out);
        return $out;
    }

    /** @return array<string,list<string>> uid → claves de derivados usadas (para la limpieza posterior) */
    public function keys(): array
    {
        $o = [];
        foreach ($this->used as $uid => $u) {
            $o[$uid] = array_keys($u['recipes']);
        }
        ksort($o);
        return $o;
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
