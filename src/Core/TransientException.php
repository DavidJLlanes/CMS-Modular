<?php
declare(strict_types=1);

namespace LuxAeterna\Core;

/** Fallo que puede resolverse reintentando (bloqueo ocupado, E/S momentánea). Los trabajos lo reintentan de forma acotada. */
final class TransientException extends LuxException
{
}
