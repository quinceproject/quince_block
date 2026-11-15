<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Block\Ui;

use Quince\Block\Tools\RuleNormalizer;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Czytelny opis reguły na liście reguł bloku i w testerze: nazwy zamiast numerów.
 *
 * Definicja pola z `RuleTypes::definitions()` nie musi mieć etykiety; wtedy
 * etykieta wynika ze źródła albo nazwy pola (angielska, tłumaczy kontroler).
 */
final class RuleSummary
{
    private const SOURCE_LABELS = [
        'category' => 'Categories',
        'manufacturer' => 'Brands',
        'supplier' => 'Suppliers',
        'feature' => 'Feature',
        'feature_value' => 'Value',
        'group' => 'Customer groups',
        'country' => 'Countries',
        'currency' => 'Currencies',
        'language' => 'Languages',
        'product' => 'Products',
    ];

    private const NAME_LABELS = [
        'children' => 'Include subcategories',
        'tags' => 'Tags',
        'min' => 'From',
        'max' => 'To',
        'param' => 'Parameter',
        'value' => 'Value',
        'phrase' => 'Phrase',
        'types' => 'Types',
        'state' => 'Visitor',
        'contains' => 'Address contains',
    ];

    /** @var array<string,array<int,string>> */
    private array $names = [];

    public function __construct(private readonly Choices $choices)
    {
    }

    /** @param array<string,mixed> $field */
    public static function fieldLabel(array $field): string
    {
        if (!empty($field['label'])) {
            return (string) $field['label'];
        }

        $source = (string) ($field['source'] ?? '');

        if ($source !== '' && isset(self::SOURCE_LABELS[$source])) {
            return self::SOURCE_LABELS[$source];
        }

        $name = (string) ($field['name'] ?? '');

        return self::NAME_LABELS[$name] ?? ucfirst(str_replace('_', ' ', $name));
    }

    /**
     * @param array<string,mixed> $definition definicja typu z RuleTypes
     * @param array<string,mixed> $value
     * @param callable(string):string $t tłumaczenie etykiet
     */
    public function describe(array $definition, array $value, int $idLang, int $idShop, callable $t): string
    {
        $parts = [];

        foreach ((array) ($definition['fields'] ?? []) as $field) {
            if (!is_array($field) || !isset($field['name'])) {
                continue;
            }

            $current = $value[(string) $field['name']] ?? null;
            $text = $this->fieldText($field, $current, $idLang, $idShop, $t);

            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return implode('; ', $parts);
    }

    /** @param array<string,mixed> $field */
    private function fieldText(array $field, mixed $current, int $idLang, int $idShop, callable $t): string
    {
        // Etykieta z definicji jest już przetłumaczona; tłumaczymy tylko zastępczą.
        $label = !empty($field['label']) ? (string) $field['label'] : $t(self::fieldLabel($field));
        $kind = (string) ($field['kind'] ?? 'text');
        $source = (string) ($field['source'] ?? '');

        if ($current === null || $current === '' || $current === []) {
            return '';
        }

        // Wybór z listy obiektów sklepu (cecha, wartość cechy) opisujemy jak numery.
        if ($kind === 'choice' && $source !== '') {
            $kind = 'ids';
        }

        switch ($kind) {
            case 'bool':
                return $current ? $label : '';

            case 'ids':
                $ids = array_map('intval', is_array($current) ? $current : [$current]);
                $names = $this->namesFor($source, $ids, $idLang, $idShop);
                $shown = array_slice($names, 0, 5);
                $more = count($names) - count($shown);

                return $label . ': ' . implode(', ', $shown) . ($more > 0 ? ' (+' . $more . ')' : '');

            case 'choice':
                $map = [];

                foreach (RuleNormalizer::options($field) as $option) {
                    $map[$option['value']] = $option['label'];
                }

                $values = array_map(static fn ($v): string => $map[(string) $v] ?? (string) $v, is_array($current) ? $current : [$current]);

                return $label . ': ' . implode(', ', $values);

            case 'number':
                return $label . ': ' . rtrim(rtrim(number_format((float) $current, 2, '.', ''), '0'), '.');

            default:
                $values = is_array($current) ? $current : [$current];

                return $label . ': "' . implode('", "', array_map('strval', $values)) . '"';
        }
    }

    /**
     * @param int[] $ids
     *
     * @return string[]
     */
    private function namesFor(string $source, array $ids, int $idLang, int $idShop): array
    {
        if ($source === 'product') {
            $map = $this->choices->productNames($ids, $idLang, $idShop);
        } elseif ($source === 'feature_value') {
            $map = $this->featureValueNames($ids, $idLang);
        } elseif ($source !== '') {
            if (!isset($this->names[$source])) {
                $this->names[$source] = $this->choices->names($source, $idLang, $idShop);
            }

            $map = $this->names[$source];
        } else {
            $map = [];
        }

        return array_map(static fn (int $id): string => isset($map[$id]) ? $map[$id] . ' #' . $id : '#' . $id, $ids);
    }

    /**
     * @param int[] $ids
     *
     * @return array<int,string>
     */
    private function featureValueNames(array $ids, int $idLang): array
    {
        $ids = array_values(array_filter($ids, static fn (int $i): bool => $i > 0));

        if ($ids === []) {
            return [];
        }

        $rows = \Db::getInstance()->executeS(
            'SELECT id_feature_value, value FROM `' . _DB_PREFIX_ . 'feature_value_lang`'
            . ' WHERE id_lang = ' . $idLang . ' AND id_feature_value IN (' . implode(',', array_map('intval', $ids)) . ')'
        ) ?: [];
        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row['id_feature_value']] = (string) $row['value'];
        }

        return $out;
    }
}
