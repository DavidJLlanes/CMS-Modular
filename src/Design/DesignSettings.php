<?php
declare(strict_types=1);

namespace CMSModular\Design;

/**
 * Ajustes de diseño y su precedencia. Niveles, de menor a mayor prioridad:
 *   global → por tipo de contenido → por página → por instancia (la colocación concreta de un componente en una página).
 * Cada nivel puede (a) elegir otro diseño para una ranura con su id, (b) desactivarla con off, o (c) solo cambiar valores.
 * Elegir un diseño distinto reinicia los valores heredados de niveles inferiores.
 */
final class DesignSettings
{
    public const SLOTS = ['topbar', 'header', 'hero', 'footer'];
    public const DEFAULT_SLOTS = ['header' => ['id' => 'cabecera-basica'], 'footer' => ['id' => 'footer-basico']];

    /** @param array<string,mixed> $raw JSON guardado */
    public function __construct(public readonly array $raw, private readonly DesignRegistry $registry)
    {
    }

    /** @return array{id:string,design:array<string,mixed>,values:array<string,mixed>}|null null = ranura apagada */
    public function resolve(string $slot, string $type, string $uid): ?array
    {
        $levels = [
            $this->raw['global'][$slot] ?? self::DEFAULT_SLOTS[$slot] ?? null,
            $this->raw['types'][$type][$slot] ?? null,
            $this->raw['pages'][$uid][$slot] ?? null,
            $this->raw['instances'][$uid][$slot] ?? null,
        ];
        $cur = null;
        foreach ($levels as $e) {
            if (!is_array($e)) {
                continue;
            }
            if (!empty($e['off'])) {
                $cur = null;
            } elseif (isset($e['id']) && is_string($e['id'])) {
                $cur = ['id' => $e['id'], 'values' => (array)($e['values'] ?? [])];
            } elseif ($cur !== null && isset($e['values']) && is_array($e['values'])) {
                $cur['values'] = array_replace($cur['values'], $e['values']);
            }
        }
        if ($cur === null) {
            return null;
        }
        $d = $this->registry->get($cur['id']);
        if ($d === null || $d['type'] !== $slot) {
            return null; // diseño retirado o de otra familia: la ranura se omite y se avisa en el inventario
        }
        $values = $d['defaults'];
        foreach ($cur['values'] as $k => $v) {
            if (isset($d['fields'][$k]) && ($c = FieldSchema::coerce($d['fields'][$k], $v)) !== null) {
                $values[$k] = $c;
            }
        }
        return ['id' => $cur['id'], 'design' => $d, 'values' => $values];
    }

    /** Ranuras elegidas que ya no existen o no coinciden con su familia. @return list<string> */
    public function problems(): array
    {
        $p = [];
        $each = function (array $levels, string $where) use (&$p): void {
            foreach ($levels as $slot => $e) {
                if (is_array($e) && isset($e['id'])) {
                    $d = $this->registry->get((string)$e['id']);
                    if ($d === null) {
                        $p[] = "$where/$slot: el diseño «{$e['id']}» ya no existe.";
                    } elseif ($d['type'] !== $slot) {
                        $p[] = "$where/$slot: «{$e['id']}» no es de tipo $slot.";
                    }
                }
            }
        };
        $each((array)($this->raw['global'] ?? []), 'global');
        foreach (['types', 'pages', 'instances'] as $lv) {
            foreach ((array)($this->raw[$lv] ?? []) as $k => $slots) {
                $each((array)$slots, "$lv:$k");
            }
        }
        return $p;
    }

    /**
     * Valida y normaliza ajustes recibidos del panel. @param array<string,mixed> $in @throws \InvalidArgumentException
     * @return array<string,mixed>
     */
    public static function validate(array $in, DesignRegistry $reg, FontCatalog $fonts, ?string $logoDir = null): array
    {
        $out = ['global' => [], 'types' => [], 'pages' => [], 'instances' => []];
        $globalIds = [];
        foreach ((array)($in['global'] ?? []) as $gs => $ge) {
            if (is_array($ge) && isset($ge['id']) && is_string($ge['id'])) {
                $globalIds[$gs] = $ge['id'];
            }
        }
        $entry = static function (mixed $e, string $slot) use ($reg, $globalIds): array {
            if (!is_array($e)) {
                throw new \InvalidArgumentException("Ajuste de $slot no válido.");
            }
            if (!empty($e['off'])) {
                return ['off' => true];
            }
            $r = [];
            if (isset($e['id']) && $e['id'] !== '') {
                $d = $reg->get((string)$e['id']);
                if ($d === null || $d['type'] !== $slot) {
                    throw new \InvalidArgumentException("El diseño «{$e['id']}» no está disponible para $slot.");
                }
                $r['id'] = $d['id'];
            }
            if (isset($e['values']) && is_array($e['values']) && $e['values'] !== []) {
                $d = $reg->get($r['id'] ?? ($globalIds[$slot] ?? (self::DEFAULT_SLOTS[$slot]['id'] ?? '')));
                $r['values'] = $d === null ? [] : FieldSchema::validate($d['fields'], $e['values']);
            }
            return $r;
        };
        foreach ((array)($in['global'] ?? []) as $slot => $e) {
            if (!in_array($slot, self::SLOTS, true)) {
                throw new \InvalidArgumentException("Ranura desconocida: $slot.");
            }
            $out['global'][$slot] = $entry($e, $slot);
        }
        foreach (['types' => '/^[a-z][a-z0-9_-]{0,30}$/', 'pages' => '/^c_[A-Za-z0-9]{4,40}$/', 'instances' => '/^c_[A-Za-z0-9]{4,40}$/'] as $lv => $keyRe) {
            foreach ((array)($in[$lv] ?? []) as $k => $slots) {
                if (!preg_match($keyRe, (string)$k) || !is_array($slots)) {
                    throw new \InvalidArgumentException("Clave no válida en $lv.");
                }
                foreach ($slots as $slot => $e) {
                    if (!in_array($slot, self::SLOTS, true)) {
                        throw new \InvalidArgumentException("Ranura desconocida: $slot.");
                    }
                    $out[$lv][(string)$k][$slot] = $entry($e, $slot);
                }
            }
        }
        $out['theme'] = Theme::normalize((array)($in['theme'] ?? []), $fonts);
        $out['logo'] = Logo::normalize((array)($in['logo'] ?? []), $logoDir);
        return $out;
    }
}
