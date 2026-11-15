<?php
declare(strict_types=1);

namespace LuxAeterna\Content;

/** Estado publicable del sitio en un instante: páginas, menús y redirecciones. */
final class SiteSnapshot
{
    /**
     * @param list<Page> $pages
     * @param array<string,list<array{label:string,url:string}>> $menus menú → enlaces ya resueltos
     * @param list<array{from:string,to:string}> $redirects
     * @param list<string> $warnings avisos editoriales (no bloquean)
     * @param array<string,mixed> $design ajustes de diseño (tema, ranuras, logo) congelados con el resto del contenido
     * @param array{items?:array<string,array<string,mixed>>,galleries?:array<string,array<string,mixed>>,quality?:int,max_width?:int} $media imágenes usadas y ajustes de procesado, congelados igual
     */
    public function __construct(
        public readonly array $pages,
        public readonly array $menus = [],
        public readonly array $redirects = [],
        public readonly array $warnings = [],
        public readonly array $design = [],
        public readonly array $media = [],
    ) {
    }
}
