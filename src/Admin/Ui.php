<?php
declare(strict_types=1);

namespace LuxAeterna\Admin;

/** Pequeños constructores de HTML para formularios del panel (todo escapado). */
final class Ui
{
    public static function h(?string $s): string
    {
        return View::h($s);
    }

    /** @param array<string,string> $attrs */
    public static function form(string $action, string $csrf, string $inner, array $attrs = []): string
    {
        $a = '';
        foreach ($attrs as $k => $v) {
            $a .= ' ' . $k . '="' . self::h($v) . '"';
        }
        return '<form method="post" action="' . self::h($action) . '"' . $a . '>' . View::csrf($csrf) . $inner . '</form>';
    }

    public static function button(string $label, string $class = ''): string
    {
        return '<button' . ($class !== '' ? ' class="' . self::h($class) . '"' : '') . '>' . self::h($label) . '</button>';
    }

    public static function hidden(string $name, string|int $value): string
    {
        return '<input type="hidden" name="' . self::h($name) . '" value="' . self::h((string)$value) . '">';
    }

    /** @param array<string,mixed> $def */
    public static function fieldInput(string $name, array $def, mixed $value): string
    {
        $n = 'f_' . $name;
        $label = self::h((string)$def['label']);
        $req = !empty($def['required']) ? ' required' : '';
        switch ($def['type']) {
            case 'textarea':
                return "<label>$label<textarea name=\"$n\" rows=\"4\"$req>" . self::h((string)$value) . '</textarea></label>';
            case 'number':
                return "<label>$label<input type=\"number\" step=\"any\" name=\"$n\" value=\"" . self::h((string)$value) . "\"$req></label>";
            case 'bool':
                return "<label class=\"check\"><input type=\"checkbox\" name=\"$n\" value=\"1\"" . ($value ? ' checked' : '') . "> $label</label>";
            case 'select':
                $o = '<option value="">—</option>';
                foreach ($def['options'] as $opt) {
                    $o .= '<option value="' . self::h((string)$opt) . '"' . ((string)$value === (string)$opt ? ' selected' : '') . '>' . self::h((string)$opt) . '</option>';
                }
                return "<label>$label<select name=\"$n\"$req>$o</select></label>";
            default:
                return "<label>$label<input name=\"$n\" value=\"" . self::h((string)$value) . "\" maxlength=\"" . (int)($def['max'] ?? 200) . "\"$req></label>";
        }
    }

    /** Campo de ajuste de un diseño (los nombres del formulario los decide quien llama). @param array<string,mixed> $def */
    public static function valueInput(string $name, array $def, mixed $value): string
    {
        $label = self::h((string)$def['label']);
        $n = self::h($name);
        switch ($def['type']) {
            case 'bool':
                return '<label class="check"><input type="checkbox" name="' . $n . '" value="1"' . ($value ? ' checked' : '') . '> ' . $label . '</label>';
            case 'select':
                $o = '';
                foreach ($def['options'] as $opt) {
                    $o .= '<option' . ((string)$value === (string)$opt ? ' selected' : '') . '>' . self::h((string)$opt) . '</option>';
                }
                return "<label>$label<select name=\"$n\">$o</select></label>";
            case 'color':
                return "<label>$label<input type=\"color\" name=\"$n\" value=\"" . self::h((string)$value) . '"></label>';
            case 'number':
                return "<label>$label<input type=\"number\" step=\"any\" name=\"$n\"" . (isset($def['min']) ? ' min="' . (float)$def['min'] . '"' : '') . (isset($def['max']) ? ' max="' . (float)$def['max'] . '"' : '') . ' value="' . self::h((string)$value) . '"></label>';
            case 'textarea':
                return "<label>$label<textarea name=\"$n\" rows=\"3\">" . self::h((string)$value) . '</textarea></label>';
            default:
                return "<label>$label<input name=\"$n\" maxlength=\"" . (int)($def['max'] ?? 200) . '" value="' . self::h((string)$value) . '"></label>';
        }
    }

    public static function date(int $ts): string
    {
        return date('Y-m-d H:i', $ts);
    }
}
