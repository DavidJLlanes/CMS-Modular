<?php
declare(strict_types=1);

namespace LuxAeterna\Core;

/** Identificador de operación: une el mensaje mostrado al operador con el detalle del log. */
final class Operation
{
    private static ?string $id = null;

    public static function id(): string
    {
        return self::$id ??= bin2hex(random_bytes(4));
    }

    public static function reset(): void
    {
        self::$id = null;
    }
}
