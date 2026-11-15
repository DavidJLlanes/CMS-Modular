<?php
declare(strict_types=1);

namespace LuxAeterna\Publishing;

use LuxAeterna\Content\Page;
use LuxAeterna\Content\SiteSnapshot;
use LuxAeterna\Core\Config;
use LuxAeterna\Core\LuxException;

/** Renderiza páginas con la plantilla del diseño. Lo usan por igual la publicación y la previsualización. */
final class SiteRenderer
{
    private string $template;
    public readonly string $css;
    public readonly string $cssHash;

    public function __construct(private readonly Config $config, string $layoutDir)
    {
        $this->template = self::read($layoutDir . '/template.html');
        $this->css = self::read($layoutDir . '/style.css');
        $this->cssHash = substr(hash('sha256', $this->css), 0, 10);
    }

    public function publicStylesheet(): string
    {
        return '/assets/style.' . $this->cssHash . '.css';
    }

    /** @param list<array{label:string,url:string}> $links */
    public static function navHtml(array $links): string
    {
        return implode('', array_map(static fn(array $l): string => '<a href="' . BlockRenderer::e($l['url']) . '">' . BlockRenderer::e($l['label']) . '</a>', $links));
    }

    /** @return array<string,string> variables comunes a todas las páginas */
    public function siteVars(SiteSnapshot $s, string $stylesheet, string $headExtra = ''): array
    {
        return [
            'lang' => (string)$this->config->get('site.lang', 'es'),
            'site_name' => (string)$this->config->get('site.name', 'LuxAeterna'),
            'nav' => self::navHtml($s->menus['main'] ?? []),
            'footer_nav' => self::navHtml($s->menus['footer'] ?? []),
            'stylesheet' => $stylesheet,
            'head_extra' => $headExtra,
        ];
    }

    /** @param array<string,string> $site */
    public function page(Page $p, array $site): string
    {
        return Template::render($this->template, $site + [
            'title' => $p->title,
            'heading' => $p->title,
            'description' => $p->description,
            'canonical' => $this->config->baseUrl() . $p->path(),
            'content' => BlockRenderer::render($p),
        ]);
    }

    /** @param array<string,string> $site */
    public function notFound(array $site): string
    {
        return Template::render($this->template, $site + [
            'title' => 'Página no encontrada',
            'heading' => 'Página no encontrada',
            'description' => '',
            'canonical' => $this->config->baseUrl() . '/',
            'content' => "<p>La página que buscas no existe. <a href=\"/\">Volver al inicio</a>.</p>\n",
        ]);
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
