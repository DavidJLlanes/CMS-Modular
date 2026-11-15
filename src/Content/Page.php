<?php
declare(strict_types=1);

namespace LuxAeterna\Content;

final class Page
{
    /** @param list<array{type:string,level?:int,text:string}> $blocks */
    public function __construct(
        public readonly string $id,
        public readonly string $slug,
        public readonly string $title,
        public readonly string $description,
        public readonly array $blocks,
        public readonly bool $inMenu,
    ) {
    }

    /** Ruta pública canónica: "/" o "/slug/". */
    public function path(): string
    {
        return $this->slug === '' ? '/' : '/' . $this->slug . '/';
    }
}
