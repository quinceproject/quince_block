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

use Db;
use Quince\Engine\Ui\CategoryChoices;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Listy wyboru do formularzy reguł i testera: nazwy obiektów sklepu po id.
 *
 * Źródła (`source`) pochodzą z `RuleTypes::definitions()`. Krótkie listy idą do
 * strony od razu, długie (produkty, wartości cech) przez wyszukiwanie.
 */
final class Choices
{
    /** Źródła wysyłane do strony w całości. */
    public const PRELOADED = ['category', 'manufacturer', 'supplier', 'feature', 'group', 'country', 'currency', 'language'];

    /**
     * @return array<string,array<int,array{id:int,name:string,path?:string}>>
     */
    public function preloaded(int $idLang, int $idShop): array
    {
        $out = [];

        foreach (self::PRELOADED as $source) {
            $out[$source] = $this->list($source, $idLang, $idShop);
        }

        return $out;
    }

    /** @return array<int,array{id:int,name:string,path?:string}> */
    public function list(string $source, int $idLang, int $idShop): array
    {
        $p = _DB_PREFIX_;

        switch ($source) {
            case 'category':
                return $this->categories($idLang, $idShop);
            case 'manufacturer':
                return $this->rows('SELECT id_manufacturer AS id, name FROM `' . $p . 'manufacturer` ORDER BY name');
            case 'supplier':
                return $this->rows('SELECT id_supplier AS id, name FROM `' . $p . 'supplier` ORDER BY name');
            case 'feature':
                return $this->rows(
                    'SELECT f.id_feature AS id, fl.name FROM `' . $p . 'feature` f'
                    . ' INNER JOIN `' . $p . 'feature_lang` fl ON fl.id_feature = f.id_feature AND fl.id_lang = ' . $idLang
                    . ' ORDER BY fl.name'
                );
            case 'group':
                return $this->rows(
                    'SELECT g.id_group AS id, gl.name FROM `' . $p . 'group` g'
                    . ' INNER JOIN `' . $p . 'group_lang` gl ON gl.id_group = g.id_group AND gl.id_lang = ' . $idLang
                    . ' ORDER BY g.id_group'
                );
            case 'country':
                return $this->rows(
                    'SELECT c.id_country AS id, cl.name FROM `' . $p . 'country` c'
                    . ' INNER JOIN `' . $p . 'country_lang` cl ON cl.id_country = c.id_country AND cl.id_lang = ' . $idLang
                    . ' WHERE c.active = 1 ORDER BY cl.name'
                );
            case 'currency':
                return $this->rows(
                    'SELECT id_currency AS id, iso_code AS name FROM `' . $p . 'currency` WHERE deleted = 0 ORDER BY iso_code'
                );
            case 'language':
                return $this->rows('SELECT id_lang AS id, name FROM `' . $p . 'lang` ORDER BY name');
        }

        return [];
    }

    /**
     * Wyszukiwanie produktów po nazwie, indeksie albo numerze.
     *
     * @return array<int,array{id:int,name:string}>
     */
    public function searchProducts(string $query, int $idLang, int $idShop): array
    {
        $query = trim($query);

        if ($query === '') {
            return [];
        }

        $like = "'%" . pSQL(addcslashes($query, '%_')) . "%'";
        $byId = ctype_digit($query) ? ' OR p.id_product = ' . (int) $query : '';

        return $this->rows(
            'SELECT p.id_product AS id, CONCAT(pl.name, IF(p.reference <> \'\', CONCAT(\' (\', p.reference, \')\'), \'\')) AS name'
            . ' FROM `' . _DB_PREFIX_ . 'product` p'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON pl.id_product = p.id_product AND pl.id_lang = ' . $idLang . ' AND pl.id_shop = ' . $idShop
            . ' WHERE pl.name LIKE ' . $like . ' OR p.reference LIKE ' . $like . $byId
            . ' ORDER BY pl.name LIMIT 30'
        );
    }

    /**
     * @param int[] $ids
     *
     * @return array<int,string> id => nazwa
     */
    public function productNames(array $ids, int $idLang, int $idShop): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0));

        if ($ids === []) {
            return [];
        }

        $out = [];

        foreach ($this->rows(
            'SELECT id_product AS id, name FROM `' . _DB_PREFIX_ . 'product_lang`'
            . ' WHERE id_lang = ' . $idLang . ' AND id_shop = ' . $idShop . ' AND id_product IN (' . implode(',', $ids) . ')'
        ) as $row) {
            $out[$row['id']] = $row['name'];
        }

        return $out;
    }

    /** @return array<int,array{id:int,name:string}> */
    public function featureValues(int $idFeature, int $idLang): array
    {
        if ($idFeature <= 0) {
            return [];
        }

        return $this->rows(
            'SELECT fv.id_feature_value AS id, fvl.value AS name FROM `' . _DB_PREFIX_ . 'feature_value` fv'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'feature_value_lang` fvl ON fvl.id_feature_value = fv.id_feature_value AND fvl.id_lang = ' . $idLang
            . ' WHERE fv.id_feature = ' . $idFeature . ' ORDER BY fvl.value'
        );
    }

    /** @return array<int,string> id => nazwa */
    public function names(string $source, int $idLang, int $idShop): array
    {
        $out = [];

        foreach ($this->list($source, $idLang, $idShop) as $item) {
            $out[$item['id']] = $item['name'];
        }

        return $out;
    }

    /** @return array<int,array{id:int,name:string,path:string}> */
    private function categories(int $idLang, int $idShop): array
    {
        $all = CategoryChoices::forPanel($idLang, $idShop);
        $names = [];

        foreach ($all as $category) {
            $names[$category['id']] = $category['name'];
        }

        $out = [];

        foreach ($all as $category) {
            // Ścieżka idzie od rodzica w górę; do wyświetlenia odwracamy ją od korzenia.
            $path = array_map(static fn (int $id): string => $names[$id] ?? '', array_reverse((array) ($category['sciezka'] ?? [])));
            $out[] = [
                'id' => $category['id'],
                'name' => $category['name'],
                'path' => implode(' / ', array_filter($path)),
            ];
        }

        return $out;
    }

    /** @return array<int,array{id:int,name:string}> */
    private function rows(string $sql): array
    {
        $rows = Db::getInstance()->executeS($sql) ?: [];

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']], $rows);
    }
}
