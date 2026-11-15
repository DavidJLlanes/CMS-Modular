<?php
declare(strict_types=1);

namespace LuxAeterna\Media;

use LuxAeterna\Core\Config;
use LuxAeterna\Core\LuxException;

/**
 * Ajustes de la biblioteca: calidad WebP y tamaño máximo (editables en el panel) y límites de ingesta (configuración).
 * La calidad se interpreta como el parámetro `quality` de libwebp (0–100, con pérdida): 82 es un punto de partida
 * razonable para fotografía; valores menores pesan menos y degradan más los degradados suaves.
 */
final class MediaSettings
{
    public const QUALITY_MIN = 40;
    public const QUALITY_MAX = 95;
    public const MAX_WIDTH_MIN = 640;
    public const MAX_WIDTH_MAX = 3840;
    /** Anchuras candidatas; solo se generan las que la imagen y su uso real necesitan. */
    public const LADDER = [400, 800, 1200, 1600, 2400, 3200];

    /** @param array<string,mixed> $stored ajustes guardados en la base de datos */
    public function __construct(private readonly Config $config, private readonly array $stored = [])
    {
    }

    public function quality(): int
    {
        return self::clamp((int)($this->stored['quality'] ?? $this->config->get('media.quality', 82)), self::QUALITY_MIN, self::QUALITY_MAX);
    }

    public function maxWidth(): int
    {
        return self::clamp((int)($this->stored['max_width'] ?? $this->config->get('media.max_width', 1600)), self::MAX_WIDTH_MIN, self::MAX_WIDTH_MAX);
    }

    public function maxUploadBytes(): int
    {
        return max(1, (int)$this->config->get('media.max_upload_mb', 12)) * 1048576;
    }

    public function maxPixels(): int
    {
        return max(1000000, (int)$this->config->get('media.max_pixels', 24000000));
    }

    public function maxSide(): int
    {
        return max(1000, (int)$this->config->get('media.max_side', 12000));
    }

    /** Segundos máximos por tanda de procesado (un trabajador). */
    public function maxSeconds(): int
    {
        return max(10, (int)$this->config->get('media.max_seconds', 120));
    }

    /** @return array{quality:int,max_width:int} */
    public function toArray(): array
    {
        return ['quality' => $this->quality(), 'max_width' => $this->maxWidth()];
    }

    /** @param array<string,mixed> $in @return array{quality:int,max_width:int} */
    public static function validate(array $in): array
    {
        $q = filter_var($in['quality'] ?? null, FILTER_VALIDATE_INT);
        $w = filter_var($in['max_width'] ?? null, FILTER_VALIDATE_INT);
        if ($q === false || $q < self::QUALITY_MIN || $q > self::QUALITY_MAX) {
            throw new LuxException('La calidad WebP debe estar entre ' . self::QUALITY_MIN . ' y ' . self::QUALITY_MAX . '.');
        }
        if ($w === false || $w < self::MAX_WIDTH_MIN || $w > self::MAX_WIDTH_MAX) {
            throw new LuxException('El ancho máximo debe estar entre ' . self::MAX_WIDTH_MIN . ' y ' . self::MAX_WIDTH_MAX . ' px.');
        }
        return ['quality' => $q, 'max_width' => $w];
    }

    private static function clamp(int $v, int $lo, int $hi): int
    {
        return max($lo, min($hi, $v));
    }
}
