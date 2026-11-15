<?php
declare(strict_types=1);

namespace LuxAeterna\Admin;

use LuxAeterna\Core\LuxException;
use LuxAeterna\Design\Contrast;
use LuxAeterna\Design\DesignRegistry;
use LuxAeterna\Design\DesignSettings;
use LuxAeterna\Design\FontCatalog;
use LuxAeterna\Design\Logo;
use LuxAeterna\Design\Theme;
use LuxAeterna\Publishing\ReleaseStore;
use LuxAeterna\Security\Authorizer;

/** Diseño del sitio: ranuras (cabecera, barra, hero, pie), tema, tipografía, logo, fuentes e inventario de recursos. Solo administradores. */
final class DesignController
{
    /** @param \Closure(?array,?string,?string):array<string,mixed> $ctx */
    public function __construct(private readonly Services $sv, private readonly string $prefix, private readonly \Closure $ctx, private readonly string $ip)
    {
    }

    private function registry(): DesignRegistry
    {
        return new DesignRegistry($this->sv->config->root . '/components', $this->sv->config->path('data') . '/cache/designs.json', \LuxAeterna\Core\Version::CURRENT);
    }

    private function fonts(): FontCatalog
    {
        return new FontCatalog($this->sv->config->root . '/components/fonts');
    }

    /** @param array<string,mixed> $user */
    public function handle(Request $r, array $user, string $p): ?Response
    {
        if (!str_starts_with($p, '/design')) {
            return null;
        }
        if (!Authorizer::can($user, 'site.manage')) {
            return new Response(403, View::page('Prohibido', '<h1>Sin permiso</h1><p>Tu rol no permite esta acción.</p>', ($this->ctx)($user, null, null)));
        }
        if ($p === '/design/swatch.css') {
            return $this->swatches($r);
        }
        if (preg_match('#^/design/preview/([a-z][a-z0-9-]{1,40})\.webp$#', $p, $m)) {
            $d = $this->registry()->get($m[1]);
            $f = $d !== null && isset($d['files']['preview']) ? $this->sv->config->root . '/components/' . $d['dir'] . '/' . $d['files']['preview'] : null;
            return $f !== null && is_file($f)
                ? new Response(200, (string)file_get_contents($f), ['Content-Type' => 'image/webp', 'Cache-Control' => 'private, max-age=300', 'X-Content-Type-Options' => 'nosniff'])
                : new Response(404, 'No encontrado', ['Content-Type' => 'text/plain; charset=utf-8']);
        }
        if ($p === '/design/refresh' && $r->method === 'POST') {
            $this->registry()->refresh();
            $this->audit('design.refresh', $user);
            return Response::redirect($this->url('/design'));
        }
        if ($p === '/design/save' && $r->method === 'POST') {
            return $this->save($r, $user);
        }
        if ($p === '/design') {
            return $this->page($user);
        }
        return null;
    }

    /** @param array<string,mixed> $user */
    private function save(Request $r, array $user): Response
    {
        $raw = $this->sv->designSettings();
        $in = $raw;
        $theme = (array)($raw['theme'] ?? []);
        $post = $r->post;
        try {
            // ranuras globales y por tipo
            foreach (['global' => 'g', 'types' => 't'] as $lv => $key) {
                foreach ((array)($post[$key] ?? []) as $scope => $slots) {
                    foreach ((array)$slots as $slot => $e) {
                        $e = (array)$e;
                        $sel = (string)($e['id'] ?? '');
                        $entry = $sel === '_off' ? ['off' => true] : ($sel === '' && $lv === 'types' ? null : ($sel === '' ? [] : ['id' => $sel]));
                        if ($entry !== null && isset($entry['id']) && ($d = $this->registry()->get($sel)) !== null && $lv === 'global') {
                            $vals = [];
                            foreach ($d['fields'] as $k => $def) {
                                $posted = (array)($e['values'] ?? []);
                                if ($def['type'] === 'bool') {
                                    $vals[$k] = isset($posted[$k]);
                                } elseif (array_key_exists($k, $posted)) {
                                    $vals[$k] = $posted[$k];
                                }
                            }
                            $entry['values'] = $vals;
                        }
                        if ($lv === 'global') {
                            $in['global'][$slot] = $entry ?? [];
                        } elseif ($entry === null) {
                            unset($in['types'][$scope][$slot]);
                        } else {
                            $in['types'][$scope][$slot] = $entry;
                        }
                    }
                }
            }
            // tema
            if (isset($post['preset'])) {
                $preset = (string)$post['preset'];
                if (!isset(Theme::PALETTES[$preset])) {
                    throw new \InvalidArgumentException('Paleta desconocida.');
                }
                $theme['colors'] = Theme::PALETTES[$preset];
            }
            if (is_array($post['theme'] ?? null)) {
                $t = $post['theme'];
                $theme['colors'] = array_replace((array)($theme['colors'] ?? []), array_filter((array)($t['colors'] ?? []), 'is_string'));
                $comp = [];
                foreach ((array)($t['components'] ?? []) as $slot => $vals) {
                    foreach ((array)$vals as $role => $v) {
                        if (is_string($v) && trim($v) !== '') {
                            $comp[$slot][$role] = trim($v);
                        }
                    }
                }
                $theme['components'] = $comp;
                $theme['typography'] = array_replace_recursive((array)($theme['typography'] ?? []), (array)($t['typography'] ?? []));
            }
            $in['theme'] = $theme;
            if (is_array($post['logo'] ?? null)) {
                $in['logo'] = (array)$post['logo'];
            }
            $norm = DesignSettings::validate($in, $this->registry(), $this->fonts(), $this->sv->config->root . '/components/logos');
            $norm['pages'] = $raw['pages'] ?? [];
            $norm['instances'] = $raw['instances'] ?? [];
            // los niveles por página/instancia se conservan tal cual (se validan al editarlos por CLI)
            if (isset($post['check'])) {
                return $this->page($user, null, null, $norm, true);
            }
            $this->sv->settings->set('design', (string)json_encode($norm, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $this->audit('design.save', $user);
            return $this->page($user, null, 'Diseño guardado. Se aplica en la próxima publicación.');
        } catch (\InvalidArgumentException $e) {
            return $this->page($user, $e->getMessage());
        }
    }

    /** CSS de muestras de contraste (el panel no admite estilos en línea): solo pares #rrggbb-#rrggbb validados. */
    private function swatches(Request $r): Response
    {
        $css = '';
        foreach (array_slice(explode(',', (string)($r->query['c'] ?? '')), 0, 60) as $i => $pair) {
            if (preg_match('/^([0-9a-f]{6})-([0-9a-f]{6})$/', $pair, $m)) {
                $css .= ".sw-$i{color:#{$m[1]};background:#{$m[2]};padding:.15rem .5rem;border:1px solid #888;border-radius:4px}\n";
            }
        }
        return new Response(200, $css, ['Content-Type' => 'text/css; charset=utf-8', 'Cache-Control' => 'private, max-age=60']);
    }

    /** @param array<string,mixed> $user @param ?array<string,mixed> $preview ajustes sin guardar (comprobación de contraste) */
    private function page(array $user, ?string $error = null, ?string $flash = null, ?array $preview = null, bool $checked = false): Response
    {
        $raw = $preview ?? $this->sv->designSettings();
        $reg = $this->registry();
        $idx = $reg->index();
        $fonts = $this->fonts();
        $csrf = ($this->ctx)($user, null, null)['csrf'];
        try {
            $theme = Theme::normalize((array)($raw['theme'] ?? []), $fonts);
        } catch (\InvalidArgumentException) {
            $theme = Theme::defaults();
        }
        try {
            $logo = Logo::normalize((array)($raw['logo'] ?? []), $this->sv->config->root . '/components/logos');
        } catch (\InvalidArgumentException) {
            $logo = Logo::defaults();
        }
        $audit = Contrast::audit($theme['colors'], $theme['components']);
        $pairs = [];
        $rows = '';
        foreach ($audit as $i => $a) {
            $rows .= '<tr><td>' . View::h($a['pair']) . '</td><td>' . $a['ratio'] . ':1</td><td>' . ($a['ok'] ? '✓ AA' : '<strong>✗ por debajo de 4,5:1</strong>') . '</td><td><span class="sw-' . $i . '">Muestra</span></td></tr>';
        }
        foreach ($this->auditPairs($theme) as $pr) {
            $pairs[] = ltrim($pr[0], '#') . '-' . ltrim($pr[1], '#');
        }
        $sel = static fn(string $cur, string $v): string => $cur === $v ? ' selected' : '';
        $body = '<h1>Diseño del sitio</h1>';
        // diseños detectados
        $body .= '<h2>Diseños disponibles</h2><div class="designs">';
        foreach ($idx['designs'] as $d) {
            $body .= '<div class="card">' . (isset($d['files']['preview']) ? '<img src="' . $this->url('/design/preview/' . $d['id'] . '.webp') . '" alt="" width="160" height="60"> ' : '')
                . '<strong>' . View::h($d['name']) . '</strong> <small>' . View::h(DesignRegistry::TYPES[$d['type']]) . ' · v' . View::h($d['version']) . ' · ' . View::h($d['id']) . '</small>'
                . ($d['description'] !== '' ? '<br><small>' . View::h($d['description']) . '</small>' : '') . '</div>';
        }
        $body .= '</div>';
        foreach ($idx['errors'] as $e) {
            $body .= '<p class="error"><strong>' . View::h($e['dir']) . ':</strong> ' . View::h($e['message']) . '</p>';
        }
        $body .= Ui::form($this->url('/design/refresh'), $csrf, Ui::button('Detectar diseños de nuevo'));
        // formulario principal
        $f = '<h2>Ranuras globales</h2><p class="muted">Un diseño distinto por tipo de contenido se elige abajo; por página o instancia, con <code>bin/lux design:set</code>. Si eliges otro diseño, guarda para ver sus campos.</p>';
        foreach (DesignSettings::SLOTS as $slot) {
            $cur = $raw['global'][$slot] ?? DesignSettings::DEFAULT_SLOTS[$slot] ?? [];
            $curId = !empty($cur['off']) ? '_off' : (string)($cur['id'] ?? '');
            $f .= '<fieldset><legend>' . View::h(DesignRegistry::TYPES[$slot]) . '</legend><label>Diseño<select name="g[global][' . $slot . '][id]">'
                . '<option value="_off"' . $sel($curId, '_off') . '>— ninguno —</option>';
            foreach ($reg->byType($slot) as $d) {
                $f .= '<option value="' . View::h($d['id']) . '"' . $sel($curId, $d['id']) . '>' . View::h($d['name']) . '</option>';
            }
            $f .= '</select></label>';
            $d = $curId !== '' && $curId !== '_off' ? $reg->get($curId) : null;
            foreach ((array)($d['fields'] ?? []) as $name => $def) {
                $f .= $this->valueInput("g[global][$slot][values][$name]", $def, $cur['values'][$name] ?? $d['defaults'][$name]);
            }
            $f .= '</fieldset>';
        }
        $f .= '<h2>Por tipo de contenido</h2><p class="muted">«Heredar» usa la configuración global.</p>';
        foreach ($this->sv->types->labels() as $type => $label) {
            $f .= '<fieldset><legend>' . View::h($label) . '</legend>';
            foreach (DesignSettings::SLOTS as $slot) {
                $cur = $raw['types'][$type][$slot] ?? null;
                $curId = $cur === null ? '' : (!empty($cur['off']) ? '_off' : (string)($cur['id'] ?? ''));
                $f .= '<label>' . View::h(DesignRegistry::TYPES[$slot]) . '<select name="t[' . View::h($type) . '][' . $slot . '][id]"><option value="">Heredar</option><option value="_off"' . $sel($curId, '_off') . '>— ninguno —</option>';
                foreach ($reg->byType($slot) as $d) {
                    $f .= '<option value="' . View::h($d['id']) . '"' . $sel($curId, $d['id']) . '>' . View::h($d['name']) . '</option>';
                }
                $f .= '</select></label>';
            }
            $f .= '</fieldset>';
        }
        // colores
        $f .= '<h2>Colores</h2><p>Paletas: ';
        foreach (array_keys(Theme::PALETTES) as $n) {
            $f .= '<button name="preset" value="' . $n . '">' . ucfirst($n) . '</button> ';
        }
        $f .= '</p><div class="grid">';
        foreach (Theme::ROLES as $role => $label) {
            $f .= '<label>' . View::h($label) . '<input type="color" name="theme[colors][' . $role . ']" value="' . View::h($theme['colors'][$role]) . '"></label>';
        }
        $f .= '</div><h3>Colores por componente <small>(vacío = heredar)</small></h3><div class="grid">';
        foreach (Theme::COMPONENT_ROLES as $slot => $roles) {
            foreach ($roles as $role) {
                $f .= '<label>' . View::h(DesignRegistry::TYPES[$slot]) . ' · ' . View::h(Theme::ROLES[$role] ?? $role) . '<input name="theme[components][' . $slot . '][' . $role . ']" value="' . View::h($theme['components'][$slot][$role] ?? '') . '" placeholder="#rrggbb" maxlength="7" pattern="#[0-9a-fA-F]{6}"></label>';
            }
        }
        $f .= '</div>';
        // tipografía
        $t = $theme['typography'];
        $famOpts = static function (string $cur) use ($fonts, $sel): string {
            $o = '<option value="system"' . $sel($cur, 'system') . '>Sistema (sin descargas)</option>';
            foreach ($fonts->all() as $x) {
                $o .= '<option value="' . View::h($x['slug']) . '"' . $sel($cur, $x['slug']) . '>' . View::h($x['family']) . ' · ' . View::h($x['license']) . '</option>';
            }
            return $o;
        };
        $f .= '<h2>Tipografía</h2><div class="grid">'
            . '<label>Texto: familia<select name="theme[typography][body][family]">' . $famOpts($t['body']['family']) . '</select></label>'
            . '<label>Texto: tamaño (px)<input type="number" min="14" max="22" step="1" name="theme[typography][body][size]" value="' . $t['body']['size'] . '"></label>'
            . '<label>Interlineado<input type="number" min="1.2" max="2.2" step="0.05" name="theme[typography][body][line]" value="' . $t['body']['line'] . '"></label>'
            . '<label>Alineación<select name="theme[typography][body][align]">';
        foreach (Theme::ALIGN as $k => $l) {
            $f .= '<option value="' . $k . '"' . $sel($t['body']['align'], $k) . '>' . $l . '</option>';
        }
        $f .= '</select></label><label>Títulos: familia<select name="theme[typography][heading][family]">' . $famOpts($t['heading']['family']) . '</select></label>'
            . '<label>Títulos: peso<select name="theme[typography][heading][weight]"><option value="400"' . $sel((string)$t['heading']['weight'], '400') . '>Normal</option><option value="700"' . $sel((string)$t['heading']['weight'], '700') . '>Negrita</option></select></label>'
            . '<label>Espaciado<select name="theme[typography][spacing]">';
        foreach (['compact' => 'Compacto', 'normal' => 'Normal', 'airy' => 'Amplio'] as $k => $l) {
            $f .= '<option value="' . $k . '"' . $sel($t['spacing'], $k) . '>' . $l . '</option>';
        }
        $f .= '</select></label><label>Ancho del contenido (rem)<input type="number" min="30" max="80" step="1" name="theme[typography][width]" value="' . $t['width'] . '"></label></div>';
        // logo
        $f .= '<h2>Logo</h2><div class="grid"><label>Tipo<select name="logo[mode]"><option value="text"' . $sel($logo['mode'], 'text') . '>Texto</option><option value="image"' . $sel($logo['mode'], 'image') . '>Imagen</option></select></label>'
            . '<label>Texto <small>(vacío = nombre del sitio)</small><input name="logo[text]" maxlength="80" value="' . View::h($logo['text']) . '"></label>'
            . '<label>Imagen <small>(de components/logos/)</small><select name="logo[image]"><option value="">—</option>';
        foreach (Logo::available($this->sv->config->root . '/components/logos') as $img) {
            $f .= '<option value="' . View::h($img) . '"' . $sel($logo['image'], $img) . '>' . View::h($img) . '</option>';
        }
        $f .= '</select></label><label>Texto alternativo<input name="logo[alt]" maxlength="120" value="' . View::h($logo['alt']) . '"></label></div>'
            . '<p class="muted">Anchos en px. El alto sale de la proporción de la imagen: nunca se deforma. El móvil no puede superar al escritorio.</p><div class="grid">';
        foreach (Logo::PLACES as $place => $label) {
            [$dsk, $mob] = $logo['sizes'][$place];
            $f .= '<label>' . View::h($label) . ' · escritorio<input type="number" min="16" max="600" name="logo[sizes][' . $place . '][]" value="' . $dsk . '"></label>'
                . '<label>' . View::h($label) . ' · móvil<input type="number" min="16" max="600" name="logo[sizes][' . $place . '][]" value="' . $mob . '"></label>';
        }
        $f .= '</div>' . Ui::button('Guardar diseño') . ' <button name="check" value="1">Comprobar contraste sin guardar</button>';
        $body .= Ui::form($this->url('/design/save'), $csrf, $f);
        $body .= '<h2>Contraste de lectura' . ($checked ? ' <small>(valores sin guardar)</small>' : '') . '</h2><table><tr><th>Par</th><th>Ratio</th><th>WCAG AA</th><th></th></tr>' . $rows . '</table>';
        // fuentes e inventario
        $body .= '<h2>Catálogo de fuentes (local)</h2><ul>';
        foreach ($fonts->all() as $x) {
            $miss = $fonts->missingChars($x['slug'], [(string)$this->sv->config->get('site.lang', 'es')]);
            $body .= '<li><strong>' . View::h($x['family']) . '</strong> · ' . View::h($x['license']) . ' · ' . count($x['variants']) . ' variantes · ' . ($miss === [] ? 'cubre los caracteres españoles' : '<strong>no cubre: ' . View::h(implode(' ', $miss)) . '</strong>') . '</li>';
        }
        $body .= '</ul><p class="muted">Añadir una familia (con red, desde el servidor): <code>bin/lux fonts:add "Nombre" --license=OFL-1.1</code>. En la web publicada solo se sirven las variantes usadas, desde tu dominio.</p>';
        $body .= $this->inventoryHtml();
        $head = ['swatches' => $this->url('/design/swatch.css') . '?c=' . implode(',', $pairs)];
        $c = ($this->ctx)($user, $error, $flash);
        $c['extra_css'] = $head['swatches'];
        return new Response($error !== null ? 422 : 200, View::page('Diseño', $body, $c));
    }

    /** @param array<string,mixed> $theme @return list<array{0:string,1:string}> */
    private function auditPairs(array $theme): array
    {
        $c = $theme['colors'];
        $p = [[$c['text'], $c['bg']], [$c['text'], $c['surface']], [$c['muted'], $c['bg']], [$c['muted'], $c['surface']], [$c['link'], $c['bg']], [$c['link'], $c['surface']], [$c['primary_text'], $c['primary']]];
        foreach (['header', 'topbar', 'hero', 'footer'] as $slot) {
            $o = $theme['components'][$slot] ?? [];
            if ($o === []) {
                continue;
            }
            $bg = $o['bg'] ?? $c['surface'];
            foreach (['text', 'link'] as $r) {
                if (isset($o[$r])) {
                    $p[] = [$o[$r], $bg];
                }
            }
        }
        return $p;
    }

    /** @param array<string,mixed> $def */
    private function valueInput(string $name, array $def, mixed $value): string
    {
        $label = View::h((string)$def['label']);
        $n = View::h($name);
        switch ($def['type']) {
            case 'bool':
                return '<label class="check"><input type="checkbox" name="' . $n . '" value="1"' . ($value ? ' checked' : '') . '> ' . $label . '</label>';
            case 'select':
                $o = '';
                foreach ($def['options'] as $opt) {
                    $o .= '<option' . ((string)$value === (string)$opt ? ' selected' : '') . '>' . View::h((string)$opt) . '</option>';
                }
                return "<label>$label<select name=\"$n\">$o</select></label>";
            case 'color':
                return "<label>$label<input type=\"color\" name=\"$n\" value=\"" . View::h((string)$value) . '"></label>';
            case 'number':
                return "<label>$label<input type=\"number\" step=\"any\" name=\"$n\" value=\"" . View::h((string)$value) . '"></label>';
            case 'textarea':
                return "<label>$label<textarea name=\"$n\" rows=\"3\">" . View::h((string)$value) . '</textarea></label>';
            default:
                return "<label>$label<input name=\"$n\" maxlength=\"" . (int)($def['max'] ?? 200) . '" value="' . View::h((string)$value) . '"></label>';
        }
    }

    private function inventoryHtml(): string
    {
        $f = (new ReleaseStore($this->sv->config->path('publish')))->currentPath() . '/' . ReleaseStore::INVENTORY;
        $inv = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
        if (!is_array($inv)) {
            return '<h2>Recursos por página</h2><p class="muted">Publica el sitio para ver el inventario.</p>';
        }
        $h = '<h2>Recursos por página <small>(versión activa)</small></h2><p>' . count($inv['pages']) . ' páginas · módulos: ' . View::h(implode(', ', array_keys($inv['modules']))) . ' · compartidos por todas: ' . count($inv['shared']) . '</p><table><tr><th>Página</th><th>Componentes</th><th>Archivos</th></tr>';
        foreach (array_slice($inv['pages'], 0, 60, true) as $path => $pg) {
            $h .= '<tr><td>' . View::h((string)$path) . '</td><td>' . View::h(implode(', ', $pg['components'])) . '</td><td>' . count($pg['resources']) . '</td></tr>';
        }
        return $h . '</table><p class="muted">Sin dependencias remotas: la comprobación previa bloquea cualquier hoja de estilos, script, imagen o fuente externa.</p>';
    }

    private function url(string $p): string
    {
        return $this->prefix . $p;
    }

    /** @param array<string,mixed> $user @param array<string,mixed> $meta */
    private function audit(string $event, array $user, ?string $target = null, array $meta = []): void
    {
        $this->sv->audit->log($event, (int)$user['id'], (string)$user['email'], $target, $meta, $this->ip);
    }
}
