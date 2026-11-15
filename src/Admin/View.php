<?php
declare(strict_types=1);

namespace LuxAeterna\Admin;

/** Vistas del panel. Todo texto dinámico pasa por h(); no hay JavaScript ni estilos en línea (CSP estricta). */
final class View
{
    public static function h(?string $s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @param array<string,mixed> $ctx */
    public static function page(string $title, string $body, array $ctx = []): string
    {
        $p = (string)($ctx['prefix'] ?? '/admin');
        $user = $ctx['user'] ?? null;
        $nav = '';
        if (is_array($user)) {
            $nav = '<nav><a href="' . self::h($p) . '/">Inicio</a><a href="' . self::h($p) . '/account">Mi cuenta</a>'
                . (!empty($ctx['canAudit']) ? '<a href="' . self::h($p) . '/audit">Auditoría</a>' : '')
                . '<form method="post" action="' . self::h($p) . '/logout" class="inline"><input type="hidden" name="csrf" value="' . self::h((string)$ctx['csrf']) . '"><button class="link">Salir</button></form></nav>';
        }
        return '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow"><title>' . self::h($title) . ' · LuxAeterna</title>'
            . '<link rel="stylesheet" href="' . self::h($p) . '/assets/admin.css">' . (!empty($ctx['extra_css']) ? '<link rel="stylesheet" href="' . self::h((string)$ctx['extra_css']) . '">' : '') . '</head><body><header><strong>LuxAeterna</strong>' . $nav . '</header>'
            . '<main>' . (isset($ctx['flash']) ? '<p class="flash">' . self::h((string)$ctx['flash']) . '</p>' : '')
            . (isset($ctx['error']) ? '<p class="error" role="alert">' . self::h((string)$ctx['error']) . '</p>' : '')
            . $body . '</main></body></html>';
    }

    public static function csrf(string $token): string
    {
        return '<input type="hidden" name="csrf" value="' . self::h($token) . '">';
    }
}
