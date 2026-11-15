<?php
declare(strict_types=1);

namespace LuxAeterna\Core;

/**
 * Error controlado. `getMessage()` es el mensaje breve para el operador (sin rutas ni secretos);
 * `detail` guarda el detalle técnico que solo se escribe en el log privado.
 */
class LuxException extends \RuntimeException
{
    public function __construct(string $message, private readonly string $detail = '', int $code = 1, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function detail(): string
    {
        return $this->detail;
    }
}
