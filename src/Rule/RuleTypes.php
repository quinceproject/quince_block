<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Block\Rule;

use Context;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Typy reguł: opis dla panelu i sprowadzanie wartości do jednego kształtu.
 *
 * Panel buduje formularze wyłącznie z `definitions()`, a przed zapisem
 * przepuszcza wartość przez `normalize()`. Front robi to samo przy budowie
 * indeksu, więc silnik reguł zawsze dostaje wartość w opisanym kształcie.
 */
final class RuleTypes
{
    public const DOMAIN = 'Modules.Quinceblock.Admin';

    public const UTM_PARAMS = ['source', 'medium', 'campaign', 'term', 'content'];
    public const DEVICES = ['mobile', 'tablet', 'desktop'];
    public const CUSTOMER_STATES = ['logged', 'guest'];
    public const PAGES = ['index', 'category', 'product', 'cart', 'order', 'cms', 'search', 'manufacturer', 'supplier', 'other'];

    /** Typy, które potrzebują produktu albo listingu, żeby cokolwiek dopasować. */
    public const CATALOG_TYPES = ['category', 'manufacturer', 'supplier', 'feature', 'tag'];

    /** @return array<int,string> */
    public static function types(): array
    {
        return [
            'category', 'manufacturer', 'supplier', 'feature', 'tag', 'customer_group', 'country',
            'cart_value', 'cart_product', 'currency', 'utm', 'search', 'device', 'customer',
            'language', 'page', 'url',
        ];
    }

    public static function exists(string $type): bool
    {
        return in_array($type, self::types(), true);
    }

    /**
     * Opis typów do formularzy panelu.
     *
     * Pole: `name` (klucz w wartości), `label`, `kind` (ids|text|number|choice|bool),
     * `source` (skąd panel bierze listę do wyboru albo null), `multiple`
     * (lista wartości czy jedna), `required`, opcjonalnie `options` (wartość => etykieta).
     *
     * @return array<string,array{label:string,fields:array<int,array<string,mixed>>}>
     */
    public static function definitions(): array
    {
        $t = static fn (string $text): string => Context::getContext()->getTranslator()->trans($text, [], self::DOMAIN);

        $ids = static fn (string $name, string $label, string $source): array => [
            'name' => $name, 'label' => $label, 'kind' => 'ids', 'source' => $source, 'multiple' => true, 'required' => true,
        ];

        return [
            'category' => ['label' => $t('Category'), 'fields' => [
                $ids('ids', $t('Categories'), 'category'),
                ['name' => 'children', 'label' => $t('Include subcategories'), 'kind' => 'bool', 'source' => null, 'multiple' => false, 'required' => false],
            ]],
            'manufacturer' => ['label' => $t('Brand'), 'fields' => [
                $ids('ids', $t('Brands'), 'manufacturer'),
            ]],
            'supplier' => ['label' => $t('Supplier'), 'fields' => [
                $ids('ids', $t('Suppliers'), 'supplier'),
            ]],
            'feature' => ['label' => $t('Product feature'), 'fields' => [
                ['name' => 'id_feature', 'label' => $t('Feature'), 'kind' => 'choice', 'source' => 'feature', 'multiple' => false, 'required' => true],
                ['name' => 'id_value', 'label' => $t('Value (any value when empty)'), 'kind' => 'choice', 'source' => 'feature_value', 'multiple' => false, 'required' => false],
            ]],
            'tag' => ['label' => $t('Product tag'), 'fields' => [
                ['name' => 'tags', 'label' => $t('Tags'), 'kind' => 'text', 'source' => null, 'multiple' => true, 'required' => true],
            ]],
            'customer_group' => ['label' => $t('Customer group'), 'fields' => [
                $ids('ids', $t('Groups'), 'group'),
            ]],
            'country' => ['label' => $t('Country'), 'fields' => [
                $ids('ids', $t('Countries'), 'country'),
            ]],
            'cart_value' => ['label' => $t('Cart value'), 'fields' => [
                ['name' => 'min', 'label' => $t('From (tax incl., default currency)'), 'kind' => 'number', 'source' => null, 'multiple' => false, 'required' => false],
                ['name' => 'max', 'label' => $t('To (tax incl., default currency)'), 'kind' => 'number', 'source' => null, 'multiple' => false, 'required' => false],
            ]],
            'cart_product' => ['label' => $t('Product in cart'), 'fields' => [
                $ids('ids', $t('Products'), 'product'),
            ]],
            'currency' => ['label' => $t('Currency'), 'fields' => [
                $ids('ids', $t('Currencies'), 'currency'),
            ]],
            'utm' => ['label' => $t('UTM parameter'), 'fields' => [
                ['name' => 'param', 'label' => $t('Parameter'), 'kind' => 'choice', 'source' => null, 'multiple' => false, 'required' => true, 'options' => [
                    'source' => 'utm_source', 'medium' => 'utm_medium', 'campaign' => 'utm_campaign', 'term' => 'utm_term', 'content' => 'utm_content',
                ]],
                ['name' => 'value', 'label' => $t('Value'), 'kind' => 'text', 'source' => null, 'multiple' => false, 'required' => true],
            ]],
            'search' => ['label' => $t('Search phrase'), 'fields' => [
                ['name' => 'phrase', 'label' => $t('Phrase contains'), 'kind' => 'text', 'source' => null, 'multiple' => false, 'required' => true],
            ]],
            'device' => ['label' => $t('Device'), 'fields' => [
                ['name' => 'types', 'label' => $t('Devices'), 'kind' => 'choice', 'source' => null, 'multiple' => true, 'required' => true, 'options' => [
                    'mobile' => $t('Phone'), 'tablet' => $t('Tablet'), 'desktop' => $t('Computer'),
                ]],
            ]],
            'customer' => ['label' => $t('Customer'), 'fields' => [
                ['name' => 'state', 'label' => $t('Customer is'), 'kind' => 'choice', 'source' => null, 'multiple' => false, 'required' => true, 'options' => [
                    'logged' => $t('Signed in'), 'guest' => $t('Not signed in'),
                ]],
            ]],
            'language' => ['label' => $t('Language'), 'fields' => [
                $ids('ids', $t('Languages'), 'language'),
            ]],
            'page' => ['label' => $t('Page type'), 'fields' => [
                ['name' => 'types', 'label' => $t('Pages'), 'kind' => 'choice', 'source' => null, 'multiple' => true, 'required' => true, 'options' => [
                    'index' => $t('Home page'),
                    'category' => $t('Category'),
                    'product' => $t('Product'),
                    'cart' => $t('Cart'),
                    'order' => $t('Checkout'),
                    'cms' => $t('CMS page'),
                    'search' => $t('Search results'),
                    'manufacturer' => $t('Brand page'),
                    'supplier' => $t('Supplier page'),
                    'other' => $t('Other pages'),
                ]],
            ]],
            'url' => ['label' => $t('Page address'), 'fields' => [
                ['name' => 'contains', 'label' => $t('Address contains'), 'kind' => 'text', 'source' => null, 'multiple' => false, 'required' => true],
            ]],
        ];
    }

    /**
     * Wartość reguły w kształcie opisanym w specyfikacji albo null, gdy typ
     * jest nieznany albo wartości nie da się użyć (np. pusta lista).
     *
     * @param mixed $value
     *
     * @return array<string,mixed>|null
     */
    public static function normalize(string $type, $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        switch ($type) {
            case 'category':
                $ids = self::ids($value['ids'] ?? []);

                return $ids === [] ? null : ['ids' => $ids, 'children' => !empty($value['children'])];

            case 'manufacturer':
            case 'supplier':
            case 'customer_group':
            case 'country':
            case 'cart_product':
            case 'currency':
            case 'language':
                $ids = self::ids($value['ids'] ?? []);

                return $ids === [] ? null : ['ids' => $ids];

            case 'feature':
                $feature = (int) ($value['id_feature'] ?? 0);
                $featureValue = isset($value['id_value']) && (int) $value['id_value'] > 0 ? (int) $value['id_value'] : null;

                return $feature > 0 ? ['id_feature' => $feature, 'id_value' => $featureValue] : null;

            case 'tag':
                $tags = [];

                foreach ((array) ($value['tags'] ?? []) as $tag) {
                    $tag = mb_strtolower(trim((string) $tag));

                    if ($tag !== '') {
                        $tags[] = $tag;
                    }
                }

                $tags = array_values(array_unique($tags));

                return $tags === [] ? null : ['tags' => $tags];

            case 'cart_value':
                $min = self::amount($value['min'] ?? null);
                $max = self::amount($value['max'] ?? null);

                return ($min === null && $max === null) ? null : ['min' => $min, 'max' => $max];

            case 'utm':
                $param = (string) ($value['param'] ?? '');
                $text = trim((string) ($value['value'] ?? ''));

                return (in_array($param, self::UTM_PARAMS, true) && $text !== '') ? ['param' => $param, 'value' => $text] : null;

            case 'search':
                $phrase = trim((string) ($value['phrase'] ?? ''));

                return $phrase === '' ? null : ['phrase' => $phrase];

            case 'device':
                $types = self::choices($value['types'] ?? [], self::DEVICES);

                return $types === [] ? null : ['types' => $types];

            case 'customer':
                $state = (string) ($value['state'] ?? '');

                return in_array($state, self::CUSTOMER_STATES, true) ? ['state' => $state] : null;

            case 'page':
                $types = self::choices($value['types'] ?? [], self::PAGES);

                return $types === [] ? null : ['types' => $types];

            case 'url':
                $contains = trim((string) ($value['contains'] ?? ''));

                return $contains === '' ? null : ['contains' => $contains];
        }

        return null;
    }

    /**
     * @param mixed $list
     *
     * @return array<int,int>
     */
    private static function ids($list): array
    {
        if (!is_array($list)) {
            $list = explode(',', (string) $list);
        }

        return array_values(array_unique(array_filter(array_map('intval', $list), static fn (int $id): bool => $id > 0)));
    }

    /**
     * @param mixed $list
     * @param array<int,string> $allowed
     *
     * @return array<int,string>
     */
    private static function choices($list, array $allowed): array
    {
        return array_values(array_intersect($allowed, array_map('strval', (array) $list)));
    }

    /** @param mixed $value */
    private static function amount($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = str_replace([',', ' '], ['.', ''], (string) $value);

        return is_numeric($value) ? max(0.0, (float) $value) : null;
    }
}
