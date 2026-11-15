<?php
declare(strict_types=1);

namespace LuxAeterna\Content;

use LuxAeterna\Core\LuxException;

/** Carga y valida contenido de ejemplo en JSON (formato versionado, schema 1). */
final class ContentLoader
{
    public const SCHEMA_VERSION = 1;
    /** Rutas que el CMS se reserva (panel, recursos, formularios, archivos técnicos). */
    public const RESERVED = ['admin', 'assets', 'forms', 'media', 'releases', 'var'];

    /** @return list<Page> */
    public function load(string $file): array
    {
        if (!is_file($file)) {
            throw new LuxException('No se encuentra el contenido de ejemplo.', "Falta $file");
        }
        try {
            $data = json_decode((string)file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new LuxException('El contenido no es JSON válido.', $e->getMessage(), 1, $e);
        }
        if (!is_array($data) || ($data['schema'] ?? null) !== self::SCHEMA_VERSION || !is_array($data['pages'] ?? null)) {
            throw new LuxException('Formato de contenido no soportado (schema ' . self::SCHEMA_VERSION . ').');
        }
        return $this->pages($data['pages']);
    }

    /**
     * @param array<int,mixed> $rows
     * @return list<Page>
     */
    public function pages(array $rows): array
    {
        $pages = [];
        $slugs = [];
        $ids = [];
        foreach ($rows as $i => $row) {
            if (!is_array($row)) {
                throw new LuxException("Página #$i no válida.");
            }
            $id = (string)($row['id'] ?? '');
            $slug = (string)($row['slug'] ?? '');
            $title = trim((string)($row['title'] ?? ''));
            if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $id) || isset($ids[$id])) {
                throw new LuxException("Identificador de página no válido o repetido: '$id'.");
            }
            $this->checkSlug($slug);
            if (isset($slugs[$slug])) {
                throw new LuxException("Colisión de rutas: '$slug' está repetido.");
            }
            if ($title === '') {
                throw new LuxException("La página '$id' no tiene título.");
            }
            $ids[$id] = $slugs[$slug] = true;
            $pages[] = new Page($id, $slug, $title, trim((string)($row['description'] ?? '')), $this->blocks($id, $row['body'] ?? []), (bool)($row['menu'] ?? false));
        }
        if (!isset($slugs[''])) {
            throw new LuxException('Falta la portada (una página con slug vacío).');
        }
        return $pages;
    }

    private function checkSlug(string $slug): void
    {
        if ($slug === '') {
            return;
        }
        if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug)) {
            throw new LuxException("Slug no válido: '$slug' (minúsculas, números y guiones).");
        }
        if (in_array($slug, self::RESERVED, true)) {
            throw new LuxException("El slug '$slug' está reservado.");
        }
    }

    /** @return list<array{type:string,level?:int,text:string}> */
    private function blocks(string $id, mixed $body): array
    {
        if (!is_array($body)) {
            throw new LuxException("El cuerpo de '$id' no es válido.");
        }
        $out = [];
        foreach ($body as $b) {
            $type = is_array($b) ? ($b['type'] ?? '') : '';
            $text = is_array($b) ? (string)($b['text'] ?? '') : '';
            if ($type === 'paragraph') {
                $out[] = ['type' => 'paragraph', 'text' => $text];
            } elseif ($type === 'heading' && in_array($b['level'] ?? 2, [2, 3, 4, 5, 6], true)) {
                $out[] = ['type' => 'heading', 'level' => (int)($b['level'] ?? 2), 'text' => $text];
            } else {
                throw new LuxException("Bloque no soportado en '$id'.");
            }
        }
        return $out;
    }
}
