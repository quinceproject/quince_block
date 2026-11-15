<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Block\Tools;

use Quince\Block\Rule\RuleTypes;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Sprowadza dane reguły z formularza albo z pliku importu do kształtu `value`
 * opisanego w `RuleTypes::definitions()` (część frontu). Panel nie zna typów reguł
 * z góry: pola, ich rodzaj i dozwolone wartości biorą się wyłącznie z tej listy.
 *
 * Wielokrotność pola: rodzaj `ids` o nazwie `ids` daje listę liczb, inna nazwa
 * jedną liczbę (`id_feature`, `id_value`). `choice` i `text` dają listę, gdy
 * definicja ma `multiple` albo pole nazywa się `types` / `tags`.
 */
final class RuleNormalizer
{
    /** @var array<string,array<string,mixed>>|null */
    private ?array $definitions = null;

    /** @return array<string,array<string,mixed>> */
    public function definitions(): array
    {
        if ($this->definitions === null) {
            $this->definitions = class_exists(RuleTypes::class) ? (array) RuleTypes::definitions() : [];
        }

        return $this->definitions;
    }

    public function isKnown(string $type): bool
    {
        return isset($this->definitions()[$type]);
    }

    /** @param array<string,mixed> $field */
    public static function isMultiple(array $field): bool
    {
        if (array_key_exists('multiple', $field)) {
            return (bool) $field['multiple'];
        }

        $kind = (string) ($field['kind'] ?? '');
        $name = (string) ($field['name'] ?? '');

        if ($kind === 'ids') {
            return $name === 'ids';
        }

        return in_array($kind, ['choice', 'text'], true) && in_array($name, ['types', 'tags'], true);
    }

    /**
     * Opcje pola `choice` jako lista `[value, label]`, niezależnie od zapisu w definicji
     * (mapa wartość => etykieta, lista wartości albo lista par).
     *
     * @param array<string,mixed> $field
     *
     * @return array<int,array{value:string,label:string}>
     */
    public static function options(array $field): array
    {
        $out = [];

        foreach ((array) ($field['options'] ?? []) as $key => $option) {
            if (is_array($option)) {
                $value = (string) ($option['value'] ?? $key);
                $out[] = ['value' => $value, 'label' => (string) ($option['label'] ?? $value)];
            } elseif (is_string($key)) {
                $out[] = ['value' => $key, 'label' => (string) $option];
            } else {
                $out[] = ['value' => (string) $option, 'label' => (string) $option];
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $input wartości pól z formularza
     *
     * @return array{0:array<string,mixed>|null,1:string} [value, błąd po angielsku albo '']
     */
    public function normalize(string $type, array $input): array
    {
        $definition = $this->definitions()[$type] ?? null;

        if (!is_array($definition)) {
            return [null, 'Unknown rule type.'];
        }

        $value = [];
        $filled = false;

        foreach ((array) ($definition['fields'] ?? []) as $field) {
            if (!is_array($field) || !isset($field['name'])) {
                continue;
            }

            $name = (string) $field['name'];
            $raw = $input[$name] ?? null;
            [$clean, $isFilled, $error] = $this->field($field, $raw);

            if ($error !== '') {
                return [null, $error];
            }

            if (!empty($field['required']) && !$isFilled) {
                return [null, 'Fill in every required field of the rule.'];
            }

            $value[$name] = $clean;
            $filled = $filled || $isFilled;
        }

        if (!$filled) {
            return [null, 'Fill in the rule before saving it.'];
        }

        // Ostateczny kształt wartości ustala część frontu: ta sama funkcja
        // sprowadza reguły przy budowie indeksu w sklepie.
        if (method_exists(RuleTypes::class, 'normalize')) {
            $value = RuleTypes::normalize($type, $value);

            if ($value === null) {
                return [null, 'Fill in the rule before saving it.'];
            }
        }

        return [$value, ''];
    }

    /**
     * @param array<string,mixed> $field
     *
     * @return array{0:mixed,1:bool,2:string} [wartość, czy wypełnione, błąd]
     */
    private function field(array $field, mixed $raw): array
    {
        $kind = (string) ($field['kind'] ?? 'text');
        $multiple = self::isMultiple($field);

        switch ($kind) {
            case 'ids':
                if ($multiple) {
                    $ids = $this->ints(is_array($raw) ? $raw : preg_split('/[\s,;]+/', (string) $raw));

                    return [$ids, $ids !== [], ''];
                }

                $id = (int) (is_array($raw) ? reset($raw) : $raw);

                return [$id > 0 ? $id : null, $id > 0, ''];

            case 'number':
                if ($raw === null || $raw === '' || !is_numeric(str_replace(',', '.', (string) $raw))) {
                    return [null, false, ''];
                }

                return [(float) str_replace(',', '.', (string) $raw), true, ''];

            case 'bool':
                return [filter_var($raw, FILTER_VALIDATE_BOOLEAN), false, ''];

            case 'choice':
                $allowed = array_column(self::options($field), 'value');

                if ($multiple) {
                    $picked = array_values(array_unique(array_filter(array_map('strval', is_array($raw) ? $raw : [$raw]),
                        static fn (string $v): bool => $v !== '' && ($allowed === [] || in_array($v, $allowed, true)))));

                    return [$picked, $picked !== [], ''];
                }

                $one = is_scalar($raw) ? (string) $raw : '';

                if ($one !== '' && $allowed !== [] && !in_array($one, $allowed, true)) {
                    return [null, false, 'One of the values is not allowed.'];
                }

                return [$one, $one !== '', ''];

            default:
                if ($multiple) {
                    $items = array_map('trim', is_array($raw) ? array_map('strval', $raw) : explode(',', (string) $raw));
                    $items = array_values(array_unique(array_filter($items, static fn (string $v): bool => $v !== '')));
                    $items = array_map(static fn (string $v): string => mb_substr($v, 0, 255), $items);

                    return [$items, $items !== [], ''];
                }

                $text = mb_substr(trim(is_scalar($raw) ? (string) $raw : ''), 0, 255);

                return [$text, $text !== '', ''];
        }
    }

    /**
     * @param array<int,mixed>|false $values
     *
     * @return int[]
     */
    private function ints(array|false $values): array
    {
        $out = [];

        foreach ($values ?: [] as $value) {
            $id = (int) $value;

            if ($id > 0) {
                $out[$id] = $id;
            }
        }

        return array_values($out);
    }
}
