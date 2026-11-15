<?php
declare(strict_types=1);

namespace LuxAeterna\Content;

final class Page
{
    /**
     * @param list<array<string,mixed>> $blocks
     * @param list<array{label:string,value:string}> $fields campos propios ya formateados para mostrar
     * @param list<string> $terms nombres de categorías y etiquetas (solo informativo)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $slug,
        public readonly string $title,
        public readonly string $description,
        public readonly array $blocks,
        public readonly bool $inMenu,
        public readonly ?string $path = null,
        public readonly string $type = 'page',
        public readonly ?int $date = null,
        public readonly array $fields = [],
        public readonly array $terms = [],
    ) {
    }

    /** Ruta pública canónica: "/" o "/ruta/". */
    public function path(): string
    {
        return $this->path ?? ($this->slug === '' ? '/' : '/' . $this->slug . '/');
    }
}
