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
     */
    public function __construct(
        public readonly array $pages,
        public readonly array $menus = [],
        public readonly array $redirects = [],
        public readonly array $warnings = [],
    ) {
    }
}
