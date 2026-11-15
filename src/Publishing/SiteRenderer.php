<?php
declare(strict_types=1);

namespace LuxAeterna\Publishing;

use LuxAeterna\Content\Page;
use LuxAeterna\Content\SiteSnapshot;
use LuxAeterna\Core\Config;
use LuxAeterna\Core\LuxException;
use LuxAeterna\Design\DesignAssembler;
use LuxAeterna\Media\ImageRenderer;
use LuxAeterna\Media\MediaStore;

/** Renderiza páginas con la plantilla del diseño. Lo usan por igual la publicación y la previsualización. */
final class SiteRenderer
{
    private string $template;
    public readonly DesignAssembler $design;
    public readonly ImageRenderer $images;
    private bool $planned = false;

    /**
     * @param array<string,mixed> $designSettings ajustes de diseño del snapshot
     * @param bool $preview URL privadas del panel en lugar de recursos públicos
     * @param array<string,mixed> $media imágenes y ajustes del snapshot (SiteSnapshot::$media)
     */
    public function __construct(private readonly Config $config, string $layoutDir, array $designSettings = [], bool $preview = false, array $media = [], ?MediaStore $store = null)
    {
        $this->template = self::read($layoutDir . '/template.html');
        $this->design = new DesignAssembler($config, dirname($layoutDir, 2), $designSettings, $preview);
        $width = (int)round((float)($this->design->theme()['width'] ?? 46) * 16);
        $this->images = new ImageRenderer((array)($media['items'] ?? []), (int)($media['quality'] ?? 82), (int)($media['max_width'] ?? 1600), $width, $store, $preview);
    }

    /** Recursos que hay que escribir junto a las páginas (ruta pública → contenido o archivo). @return array<string,array{content?:string,file?:string}> */
    public function assets(): array
    {
        $a = $this->design->assets();
        foreach ($this->images->files() as $url => $file) {
            $a[$url] = ['file' => $file];
        }
        return $a;
    }

    /** @param list<array{label:string,url:string}> $links */
    public static function navHtml(array $links): string
    {
        return implode('', array_map(static fn(array $l): string => '<a href="' . BlockRenderer::e($l['url']) . '">' . BlockRenderer::e($l['label']) . '</a>', $links));
    }

    /** @return array<string,string> variables comunes a todas las páginas */
    public function siteVars(SiteSnapshot $s, string $previewCssUrl = '', string $headExtra = ''): array
    {
        $site = [
            'lang' => (string)$this->config->get('site.lang', 'es'),
            'site_name' => (string)$this->config->get('site.name', 'LuxAeterna'),
            'nav' => self::navHtml($s->menus['main'] ?? []),
            'footer_nav' => self::navHtml($s->menus['footer'] ?? []),
            'head_extra' => $headExtra,
        ];
        $this->design->plan($s->pages, $site, $previewCssUrl);
        $this->planned = true;
        return $site;
    }

    /** @param array<string,string> $site */
    public function page(Page $p, array $site): string
    {
        return Template::render($this->template, $site + [
            'title' => $p->title,
            'heading' => $p->title,
            'description' => $p->description,
            'canonical' => $this->config->baseUrl() . $p->path(),
            'content' => BlockRenderer::render($p, $this->images),
        ] + $this->design->pageVars($p));
    }

    /** @param array<string,string> $site */
    public function notFound(array $site): string
    {
        $p = new Page('404', '404', 'Página no encontrada', '', [], false, '/404.html', 'page');
        $this->design->planExtra($p, $site);
        return Template::render($this->template, $site + [
            'title' => 'Página no encontrada',
            'heading' => 'Página no encontrada',
            'description' => '',
            'canonical' => $this->config->baseUrl() . '/',
            'content' => "<p>La página que buscas no existe. <a href=\"/\">Volver al inicio</a>.</p>\n",
        ] + $this->design->pageVars($p));
    }

    /** Página mínima de redirección para servidores estáticos sin reglas HTTP (la Fase 4 añade 301 reales). */
    public function redirectStub(string $to): string
    {
        $url = BlockRenderer::e($this->config->baseUrl() . $to);
        return '<!doctype html><html lang="' . BlockRenderer::e((string)$this->config->get('site.lang', 'es')) . '"><head><meta charset="utf-8"><title>Redirigiendo…</title>'
            . '<meta name="robots" content="noindex"><link rel="canonical" href="' . $url . '"><meta http-equiv="refresh" content="0; url=' . BlockRenderer::e($to) . '"></head>'
            . '<body><p>Esta página se ha movido: <a href="' . BlockRenderer::e($to) . '">continuar</a>.</p></body></html>' . "\n";
    }

    private static function read(string $file): string
    {
        $c = @file_get_contents($file);
        if ($c === false) {
            throw new LuxException('Falta un archivo de diseño.', "read $file");
        }
        return $c;
    }
}
