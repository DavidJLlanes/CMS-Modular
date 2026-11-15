<?php
declare(strict_types=1);

namespace LuxAeterna\Content;

use LuxAeterna\Core\LuxException;

/** Otra persona (u otra pestaña) modificó el contenido desde que se abrió el editor. */
final class ConflictException extends LuxException
{
}
