<?php
declare(strict_types=1);

namespace CMSModular\Design;

/** Campos configurables de un diseño: definición (manifiesto) y validación/coerción de valores. */
final class FieldSchema
{
    public const TYPES = ['text', 'textarea', 'bool', 'select', 'number', 'color'];
    private const NAME = '/^[a-z][a-z0-9_]{0,30}$/';

    /** @param mixed $defs @return list<string> errores de la definición */
    public static function checkDefs(mixed $defs): array
    {
        $errors = [];
        if (!is_array($defs) || (array_is_list($defs) && $defs !== [])) {
            return ['«fields» debe ser un objeto.'];
        }
        if (count($defs) > 40) {
            $errors[] = 'Demasiados campos (máximo 40).';
        }
        foreach ($defs as $name => $d) {
            $name = (string)$name;
            if (!preg_match(self::NAME, $name)) {
                $errors[] = "Nombre de campo no válido: $name.";
                continue;
            }
            if (!is_array($d) || !in_array($d['type'] ?? null, self::TYPES, true)) {
                $errors[] = "Campo $name: tipo no válido.";
                continue;
            }
            if (!isset($d['label']) || !is_string($d['label']) || $d['label'] === '' || mb_strlen($d['label']) > 80) {
                $errors[] = "Campo $name: falta la etiqueta.";
            }
            if ($d['type'] === 'select' && (!isset($d['options']) || !is_array($d['options']) || $d['options'] === [] || count($d['options']) > 30 || array_filter($d['options'], fn($o) => !is_string($o) || $o === '') !== [])) {
                $errors[] = "Campo $name: opciones no válidas.";
            }
            if (!array_key_exists('default', $d)) {
                $errors[] = "Campo $name: falta el valor por defecto.";
            } elseif (self::coerce($d, $d['default']) === null) {
                $errors[] = "Campo $name: el valor por defecto no es válido.";
            }
        }
        return $errors;
    }

    /** @param array<string,mixed> $def @return string|int|float|bool|null null = no válido */
    public static function coerce(array $def, mixed $v): string|int|float|bool|null
    {
        switch ($def['type']) {
            case 'bool':
                return is_bool($v) ? $v : (in_array($v, ['1', 1, 'on', 'true'], true) ? true : (in_array($v, ['0', 0, '', 'false', null], true) ? false : null));
            case 'number':
                if (!is_numeric($v)) {
                    return null;
                }
                $n = $v + 0;
                return (isset($def['min']) && $n < $def['min']) || (isset($def['max']) && $n > $def['max']) ? null : $n;
            case 'select':
                return is_string($v) && in_array($v, $def['options'], true) ? $v : null;
            case 'color':
                return is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtolower($v) : null;
            default:
                if (!is_string($v) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $v) || ($def['type'] === 'text' && preg_match('/[\r\n]/', $v))) {
                    return null;
                }
                return mb_strlen($v) <= (int)($def['max'] ?? ($def['type'] === 'text' ? 200 : 2000)) ? trim($v) : null;
        }
    }

    /** @param array<string,array<string,mixed>> $defs @return array<string,mixed> valores por defecto */
    public static function defaults(array $defs): array
    {
        $out = [];
        foreach ($defs as $n => $d) {
            $out[$n] = self::coerce($d, $d['default']);
        }
        return $out;
    }

    /**
     * Valida valores enviados. Los campos desconocidos son un error; los ausentes no se tocan.
     * @param array<string,array<string,mixed>> $defs @param array<string,mixed> $values
     * @return array<string,mixed> valores normalizados @throws \InvalidArgumentException
     */
    public static function validate(array $defs, array $values): array
    {
        $out = [];
        foreach ($values as $n => $v) {
            if (!isset($defs[$n])) {
                throw new \InvalidArgumentException("Campo desconocido: $n.");
            }
            $c = self::coerce($defs[$n], $v);
            if ($c === null) {
                throw new \InvalidArgumentException('Valor no válido en «' . $defs[$n]['label'] . '».');
            }
            $out[$n] = $c;
        }
        return $out;
    }
}
