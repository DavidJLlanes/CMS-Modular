<?php
declare(strict_types=1);

namespace LuxAeterna\Content;

final class Slug
{
    private const MAP = ['á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ù' => 'u',
        'ü' => 'u', 'û' => 'u', 'ñ' => 'n', 'ç' => 'c', 'ß' => 'ss', 'æ' => 'ae', 'œ' => 'oe', 'ø' => 'o'];

    /** Convierte un texto libre en slug: minúsculas, sin tildes, guiones. */
    public static function normalize(string $text): string
    {
        $t = strtr(mb_strtolower(trim($text), 'UTF-8'), self::MAP);
        $t = preg_replace('/[^a-z0-9]+/', '-', $t) ?? '';
        return mb_substr(trim($t, '-'), 0, 80);
    }

    /** @param list<string> $reserved @return string|null mensaje de error */
    public static function validate(string $slug, array $reserved = []): ?string
    {
        if ($slug === '' || !preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) || strlen($slug) > 80) {
            return 'El slug solo admite minúsculas, números y guiones (máx. 80 caracteres).';
        }
        if (in_array($slug, $reserved, true)) {
            return "El slug «{$slug}» está reservado por el sistema.";
        }
        return null;
    }
}
