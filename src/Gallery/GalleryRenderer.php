<?php
declare(strict_types=1);

namespace CMSModular\Gallery;

use CMSModular\Core\LuxException;
use CMSModular\Design\DesignRegistry;
use CMSModular\Media\ImageRenderer;
use CMSModular\Publishing\Template;

/**
 * HTML de una galería: el diseño (plantilla de carpeta + plantilla de foto) recibe fragmentos ya preparados y escapados.
 * Cada foto es un enlace a la imagen grande (funciona sin JavaScript) que el visor intercepta si está presente.
 */
final class GalleryRenderer
{
    public const VIEWER = 'lightbox';
    /** Anchuras del visor: una para pantallas normales y la máxima configurada para pantallas densas. */
    public const VIEW_WIDTHS = [1200, 3200];

    /**
     * @param array<string,array<string,mixed>> $galleries galerías congeladas por uid
     * @param array<string,array<string,mixed>> $media registros de imagen por uid
     * @param \Closure(array<string,mixed>,array<string,mixed>):string $instanceClass clase CSS de instancia para los valores del diseño
     */
    public function __construct(private readonly array $galleries, private readonly array $media, private readonly DesignRegistry $reg, private readonly string $componentsDir, private readonly ImageRenderer $img, private readonly \Closure $instanceClass, private readonly bool $preview = false)
    {
    }

    /** @var array{items:int,shown_bytes:int} fotos dibujadas y bytes aproximados que descarga un visitante sin abrir el visor */
    public array $stats = ['items' => 0, 'shown_bytes' => 0];

    public function render(string $uid): string
    {
        $g = $this->galleries[$uid] ?? null;
        $d = $g === null ? null : $this->reg->get((string)$g['layout']);
        if ($g === null || $d === null || $d['type'] !== 'gallery') {
            if ($this->preview) {
                return '<p class="lux-missing">[Galería «' . self::e($uid) . '» no disponible o con un diseño no válido]</p>' . "\n";
            }
            throw new LuxException('Una galería usada en el contenido no está disponible o su diseño no es válido (' . $uid . ').');
        }
        if ($g['items'] === []) {
            return $this->preview ? '<p class="lux-missing">[La galería «' . self::e((string)($g['title'] ?: $uid)) . '» está vacía]</p>' . "\n" : '';
        }
        $base = $this->componentsDir . '/' . $d['dir'];
        $tpl = (string)file_get_contents($base . '/' . $d['files']['template']);
        $itpl = (string)file_get_contents($base . '/' . $d['files']['item']);
        $values = array_replace($d['defaults'], array_intersect_key((array)$g['options'], $d['fields']));
        $fieldVars = [];
        foreach ($values as $k => $v) {
            $fieldVars[$k] = is_bool($v) ? ($v ? '1' : '0') : (string)$v;
        }
        $meta = $d['gallery'];
        $lb = (array)$g['lightbox'];
        $protect = (array)$g['protect'];
        $showCaptions = ($values['captions'] ?? true) !== false;
        $items = '';
        $n = count($g['items']);
        foreach ($g['items'] as $i => $it) {
            $rec = $this->media[$it['media']] ?? null;
            if ($rec === null) {
                throw new LuxException('Una foto de la galería ya no existe en la biblioteca (' . $it['media'] . ').');
            }
            $crop = $it['crop'] ?? $g['crop'];
            $crop = in_array($crop, $meta['crops'], true) ? $crop : $meta['crop_default'];
            $variants = $crop === 'free' ? $this->img->plan($rec, $meta['thumb_px']) : $this->img->planCover($rec, $meta['thumb_px'], GalleryStore::RATIO[$crop], (int)$it['fx'], (int)$it['fy']);
            $src = $variants[array_key_last($variants)];
            foreach ($variants as $v) {
                if ($v['width'] >= $meta['thumb_px']) {
                    $src = $v;
                    break;
                }
            }
            $this->stats['items']++;
            $this->stats['shown_bytes'] += $this->img->approxBytes($src['width'], $src['height']);
            $alt = (string)($it['alt'] ?? '');
            $img = '<img src="' . self::e($src['url']) . '"';
            if (count($variants) > 1) {
                $img .= ' srcset="' . self::e(implode(', ', array_map(static fn(array $v): string => $v['url'] . ' ' . $v['width'] . 'w', $variants))) . '" sizes="' . self::e($meta['sizes']) . '"';
            }
            $img .= ' width="' . $src['width'] . '" height="' . $src['height'] . '" alt="' . self::e($alt) . '" loading="lazy" decoding="async"' . (!empty($protect['nodrag']) ? ' draggable="false"' : '') . '>';
            $credit = trim((string)($it['credit'] ?? ''));
            $capText = trim((string)($it['caption'] ?? ''));
            $full = $capText . ($credit !== '' ? ($capText !== '' ? ' — ' : '') . $credit : '');
            $a = ['', ''];
            if (!empty($lb['enabled'])) {
                $big = $this->img->planWidths($rec, self::VIEW_WIDTHS);
                $last = $big[array_key_last($big)];
                $a = ['<a class="lux-g-a" href="' . self::e($last['url']) . '" data-w="' . $last['width'] . '" data-h="' . $last['height'] . '" data-srcset="'
                    . self::e(implode(', ', array_map(static fn(array $v): string => $v['url'] . ' ' . $v['width'] . 'w', $big))) . '"'
                    . ($lb['captions'] && $full !== '' ? ' data-cap="' . self::e($full) . '"' : '') . ($alt !== '' ? ' data-alt="' . self::e($alt) . '"' : '') . '>', '</a>'];
            }
            $orient = $src['width'] > $src['height'] * 1.1 ? 'wide' : ($src['height'] > $src['width'] * 1.1 ? 'tall' : 'square');
            $items .= Template::render($itpl, $fieldVars + [
                'a_open' => $a[0], 'a_close' => $a[1], 'img' => $img, 'caption' => $showCaptions && $capText !== '' ? '<span class="lux-g-cap">' . self::e($capText) . '</span>' : '',
                'index' => (string)($i + 1), 'alt' => $alt, 'cls' => 'is-' . $orient, 'title' => (string)($it['title'] ?? ''),
            ]);
        }
        $inner = Template::render($tpl, $fieldVars + ['items' => $items, 'title' => (string)$g['title'], 'count' => (string)$n]);
        $cls = ($this->instanceClass)($d, $values);
        $at = ' class="lux-gal' . ($cls !== '' ? ' ' . $cls : '') . '" data-lux-gallery="' . self::e($uid) . '"';
        if ($g['title'] !== '') {
            $at .= ' role="group" aria-label="' . self::e((string)$g['title']) . '"';
        }
        if (!empty($lb['enabled'])) {
            $at .= ' data-viewer="' . self::e((string)$lb['variant']) . '"' . (!empty($lb['counter']) ? ' data-counter' : '') . (!empty($lb['arrows']) ? ' data-arrows' : '') . (!empty($lb['loop']) ? ' data-loop' : '') . (!empty($lb['preload']) ? ' data-preload' : '');
        }
        if (!empty($protect['nodrag'])) {
            $at .= ' data-nodrag';
        }
        if (!empty($protect['noctx'])) {
            $at .= ' data-noctx';
        }
        return '<div' . $at . '>' . trim($inner) . "</div>\n";
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
