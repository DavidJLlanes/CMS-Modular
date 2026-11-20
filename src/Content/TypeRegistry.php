<?php
declare(strict_types=1);

namespace CMSModular\Content;

use CMSModular\Core\Config;
use CMSModular\Core\LuxException;

/**
 * Tipos de contenido y campos configurables mediante esquema validado (config `content.types`).
 * Integrados: `page` (ruta /slug/) y `post` (ruta /blog/slug/, con listado).
 *
 * Ejemplo de tipo propio en lux.json:
 *   "content": {"types": {"servicio": {"label": "Servicios", "prefix": "servicios",
 *      "fields": {"precio": {"type": "text", "label": "Precio", "max": 40}}}}}
 */
final class TypeRegistry
{
    public const FIELD_TYPES = ['text', 'textarea', 'number', 'bool', 'select'];
    public const GENERIC_RESERVED = ['admin', 'assets', 'forms', 'media', 'releases', 'var', 'categoria', 'etiqueta', 'sitemap', 'robots', 'feed', '404', 'favicon'];

    /** @var array<string,array{label:string,prefix:string,fields:array<string,array<string,mixed>>}> */
    private array $types = [];
    private string $blogBase;

    /** @param array<string,mixed> $custom */
    public function __construct(array $custom = [], string $blogBase = 'blog')
    {
        $this->blogBase = $blogBase;
        if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $blogBase)) {
            throw new LuxException('content.blog_base no es válido.');
        }
        $this->types = [
            'page' => ['label' => 'Página', 'prefix' => '', 'fields' => []],
            'post' => ['label' => 'Entrada', 'prefix' => $blogBase, 'fields' => []],
        ];
        foreach ($custom as $key => $def) {
            $this->add((string)$key, $def);
        }
    }

    public static function fromConfig(Config $c): self
    {
        $custom = $c->get('content.types', []);
        return new self(is_array($custom) ? $custom : [], (string)$c->get('content.blog_base', 'blog'));
    }

    public function blogBase(): string
    {
        return $this->blogBase;
    }

    /** @return array<string,string> clave → etiqueta */
    public function labels(): array
    {
        return array_map(static fn(array $t): string => $t['label'], $this->types);
    }

    public function has(string $type): bool
    {
        return isset($this->types[$type]);
    }

    /** @return array<string,array<string,mixed>> */
    public function fields(string $type): array
    {
        return $this->def($type)['fields'];
    }

    public function label(string $type): string
    {
        return $this->def($type)['label'];
    }

    /** Ruta pública "/prefijo/slug/". `$slug` vacío solo para la portada ("/"). */
    public function path(string $type, string $slug): string
    {
        $prefix = $this->def($type)['prefix'];
        return '/' . ($prefix !== '' ? $prefix . '/' : '') . ($slug !== '' ? $slug . '/' : '');
    }

    /** @return list<string> slugs de primer nivel que no pueden usar las páginas */
    public function reservedForPages(): array
    {
        $r = self::GENERIC_RESERVED;
        $r[] = $this->blogBase;
        foreach ($this->types as $t) {
            if ($t['prefix'] !== '') {
                $r[] = $t['prefix'];
            }
        }
        return array_values(array_unique($r));
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,string|int|bool> valores normalizados (solo campos declarados)
     */
    public function validateFields(string $type, array $input): array
    {
        $out = [];
        foreach ($this->def($type)['fields'] as $name => $f) {
            $raw = $input[$name] ?? ($f['type'] === 'bool' ? false : '');
            $label = (string)$f['label'];
            if ($f['type'] === 'bool') {
                $out[$name] = $raw === true || $raw === '1' || $raw === 'on' || $raw === 1;
                continue;
            }
            if (!is_string($raw) && !is_int($raw)) {
                throw new LuxException("El campo «{$label}» no es válido.");
            }
            $v = trim((string)$raw);
            if ($v === '') {
                if (!empty($f['required'])) {
                    throw new LuxException("El campo «{$label}» es obligatorio.");
                }
                $out[$name] = '';
                continue;
            }
            $max = (int)($f['max'] ?? ($f['type'] === 'textarea' ? 5000 : 200));
            if (mb_strlen($v) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $v)) {
                throw new LuxException("El campo «{$label}» es demasiado largo o contiene caracteres no válidos (máx. $max).");
            }
            if ($f['type'] === 'number') {
                if (!is_numeric($v)) {
                    throw new LuxException("El campo «{$label}» debe ser un número.");
                }
                $out[$name] = $v + 0;
            } elseif ($f['type'] === 'select') {
                if (!in_array($v, $f['options'], true)) {
                    throw new LuxException("El campo «{$label}» tiene un valor no permitido.");
                }
                $out[$name] = $v;
            } else {
                $out[$name] = $v;
            }
        }
        return $out;
    }

    /** @return array{label:string,prefix:string,fields:array<string,array<string,mixed>>} */
    private function def(string $type): array
    {
        return $this->types[$type] ?? throw new LuxException('Tipo de contenido desconocido.');
    }

    private function add(string $key, mixed $def): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]{1,30}$/', $key) || !is_array($def)) {
            throw new LuxException("Tipo de contenido no válido en la configuración: '$key'.");
        }
        if (in_array($key, ['page', 'post'], true)) {
            throw new LuxException("El tipo integrado '$key' no se puede redefinir.");
        }
        $prefix = (string)($def['prefix'] ?? str_replace('_', '-', $key));
        if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $prefix) || in_array($prefix, self::GENERIC_RESERVED, true) || $prefix === $this->blogBase) {
            throw new LuxException("Prefijo de ruta no válido o reservado para el tipo '$key'.");
        }
        foreach ($this->types as $t) {
            if ($t['prefix'] === $prefix) {
                throw new LuxException("El prefijo '$prefix' ya lo usa otro tipo.");
            }
        }
        $fields = [];
        foreach ((array)($def['fields'] ?? []) as $name => $f) {
            if (!preg_match('/^[a-z][a-z0-9_]{0,30}$/', (string)$name) || !is_array($f) || !in_array($f['type'] ?? '', self::FIELD_TYPES, true) || count($fields) >= 20) {
                throw new LuxException("Campo no válido en el tipo '$key'.");
            }
            if ($f['type'] === 'select' && (empty($f['options']) || !is_array($f['options']) || array_filter($f['options'], 'is_string') !== $f['options'])) {
                throw new LuxException("El campo '$name' del tipo '$key' necesita 'options' (lista de textos).");
            }
            $fields[(string)$name] = $f + ['label' => (string)$name];
        }
        $this->types[$key] = ['label' => (string)($def['label'] ?? $key), 'prefix' => $prefix, 'fields' => $fields];
    }
}
