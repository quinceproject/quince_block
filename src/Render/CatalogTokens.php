<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Block\Render;

use Category;
use Context;
use Db;
use Manufacturer;
use Quince\Block\Front\BlockCache;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Tokeny katalogu z parametrami: `{{products_count ...}}` i `{{products_url ...}}`.
 *
 * Pozwalają blokowi pokazać liczbę produktów i odnośnik do listy, które same
 * nadążają za katalogiem, np. kafel „Volvo: 77 narzędzi” prowadzący do listy
 * produktów Jaltest z filtrem marki pojazdu.
 *
 * Parametry (każdy opcjonalny, łączą się warunkiem „i”):
 * - `manufacturer` nazwa albo id marki produktu,
 * - `category` id kategorii, razem z podkategoriami,
 * - `feature` i `value` nazwa cechy i jej wartości w języku sklepu,
 * - `words` w products_count: odmiana słowa po liczbie, `narzędzie|narzędzia|narzędzi`
 *   (trzy formy: jeden, dwa do czterech, pięć i więcej) albo `tool|tools`,
 * - `label` w products_url: nazwa filtra w adresie, gdy w module filtrów ma
 *   własną nazwę do adresu; domyślnie nazwa cechy.
 *
 * Liczone są produkty aktywne w bieżącym sklepie i widoczne w katalogu. Wynik
 * siedzi godzinę w pamięci podręcznej modułu, więc zmiana katalogu pokazuje
 * się najpóźniej po godzinie albo po zapisie czegokolwiek w panelu modułu.
 */
final class CatalogTokens
{
    private const PATTERN = '/\{\{(products_count|products_url)((?:\s+[a-z_]+=(?:"[^"]*"|\'[^\']*\'))*)\s*\}\}/';
    private const ATTR = '/([a-z_]+)=(?:"([^"]*)"|\'([^\']*)\')/';
    private const TTL = 3600;

    private Context $context;

    public function __construct(?Context $context = null)
    {
        $this->context = $context ?? Context::getContext();
    }

    public static function has(string $html): bool
    {
        return strpos($html, '{{products_') !== false;
    }

    public function render(string $html): string
    {
        if (!self::has($html)) {
            return $html;
        }

        // Edytor treści potrafi zapisać cudzysłów jako encję. Zamieniamy je
        // tylko wewnątrz tokenów, reszta treści zostaje, jak była.
        $html = (string) preg_replace_callback(
            '/\{\{products_[^}]*\}\}/',
            static fn (array $m): string => str_replace(['&quot;', '&#34;', '&#39;', '&apos;'], ['"', '"', "'", "'"], $m[0]),
            $html
        );

        return (string) preg_replace_callback(self::PATTERN, function (array $m): string {
            $params = $this->params($m[2]);

            return $m[1] === 'products_count'
                ? $this->countText($params)
                : self::e($this->url($params));
        }, $html);
    }

    /** @return array<string,string> */
    private function params(string $raw): array
    {
        $out = [];

        if (preg_match_all(self::ATTR, $raw, $m, PREG_SET_ORDER)) {
            foreach ($m as $attr) {
                $out[$attr[1]] = trim(html_entity_decode($attr[2] !== '' ? $attr[2] : ($attr[3] ?? ''), ENT_QUOTES, 'UTF-8'));
            }
        }

        return $out;
    }

    /** @param array<string,string> $p */
    private function countText(array $p): string
    {
        $n = $this->count($p);

        if (($p['words'] ?? '') === '') {
            return (string) $n;
        }

        return self::e($n . ' ' . self::plural($n, explode('|', $p['words'])));
    }

    /** @param array<string,string> $p */
    private function count(array $p): int
    {
        $idShop = (int) $this->context->shop->id;
        $idLang = (int) $this->context->language->id;
        $key = 'count_' . $idShop . '_' . $idLang . '_' . md5((string) json_encode([
            $p['manufacturer'] ?? '', $p['category'] ?? '', $p['feature'] ?? '', $p['value'] ?? '',
        ]));

        $cached = BlockCache::get($key);

        if (is_int($cached)) {
            return $cached;
        }

        $n = $this->query($p, $idShop, $idLang);
        BlockCache::set($key, $n, self::TTL);

        return $n;
    }

    /** @param array<string,string> $p */
    private function query(array $p, int $idShop, int $idLang): int
    {
        $db = Db::getInstance(_PS_USE_SQL_SLAVE_);
        $px = _DB_PREFIX_;
        $join = '';
        $where = 'ps.`active` = 1 AND ps.`visibility` IN (\'both\', \'catalog\')';

        if (($p['manufacturer'] ?? '') !== '') {
            $idManufacturer = $this->manufacturerId($p['manufacturer']);

            if ($idManufacturer <= 0) {
                return 0;
            }

            $where .= ' AND p.`id_manufacturer` = ' . $idManufacturer;
        }

        if (($p['category'] ?? '') !== '') {
            $category = new Category((int) $p['category']);

            if (!$category->id) {
                return 0;
            }

            $join .= ' INNER JOIN `' . $px . 'category_product` cp ON cp.`id_product` = p.`id_product`'
                . ' INNER JOIN `' . $px . 'category` c ON c.`id_category` = cp.`id_category`'
                . ' AND c.`nleft` >= ' . (int) $category->nleft . ' AND c.`nright` <= ' . (int) $category->nright;
        }

        if (($p['feature'] ?? '') !== '') {
            $idFeature = (int) $db->getValue(
                'SELECT fl.`id_feature` FROM `' . $px . 'feature_lang` fl'
                . ' WHERE fl.`id_lang` = ' . $idLang . ' AND fl.`name` = \'' . pSQL($p['feature']) . '\''
            );

            if ($idFeature <= 0) {
                return 0;
            }

            $join .= ' INNER JOIN `' . $px . 'feature_product` fp ON fp.`id_product` = p.`id_product`'
                . ' AND fp.`id_feature` = ' . $idFeature;

            if (($p['value'] ?? '') !== '') {
                $join .= ' INNER JOIN `' . $px . 'feature_value_lang` fvl ON fvl.`id_feature_value` = fp.`id_feature_value`'
                    . ' AND fvl.`id_lang` = ' . $idLang . ' AND fvl.`value` = \'' . pSQL($p['value']) . '\'';
            }
        }

        return (int) $db->getValue(
            'SELECT COUNT(DISTINCT p.`id_product`) FROM `' . $px . 'product` p'
            . ' INNER JOIN `' . $px . 'product_shop` ps ON ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . $idShop
            . $join
            . ' WHERE ' . $where
        );
    }

    /**
     * Adres listy: kategoria, gdy podana, inaczej strona marki produktu. Cecha
     * i wartość idą jako filtr w formacie modułu ps_facetedsearch
     * (`?q=Cecha-Wartość`, myślnik i ukośnik w nazwach poprzedzone `\`).
     *
     * @param array<string,string> $p
     */
    private function url(array $p): string
    {
        $link = $this->context->link;
        $idLang = (int) $this->context->language->id;
        $base = '';

        if (($p['category'] ?? '') !== '' && (int) $p['category'] > 0) {
            $base = $link->getCategoryLink((int) $p['category'], null, $idLang);
        } elseif (($p['manufacturer'] ?? '') !== '') {
            $idManufacturer = $this->manufacturerId($p['manufacturer']);

            if ($idManufacturer > 0) {
                $base = $link->getManufacturerLink($idManufacturer, null, $idLang);
            }
        }

        if ($base === '') {
            return '';
        }

        $label = ($p['label'] ?? '') !== '' ? $p['label'] : ($p['feature'] ?? '');
        $value = $p['value'] ?? '';

        if ($label === '' || $value === '') {
            return $base;
        }

        $part = strtr($label, ['-' => '\\-']) . '-' . strtr($value, ['-' => '\\-']);
        $query = strtr($part, ['/' => '\\/']);

        return $base . (strpos($base, '?') === false ? '?' : '&') . 'q=' . urlencode($query);
    }

    private function manufacturerId(string $nameOrId): int
    {
        if (ctype_digit($nameOrId)) {
            return (int) $nameOrId;
        }

        return (int) Manufacturer::getIdByName($nameOrId);
    }

    /**
     * Forma słowa dla liczby. Trzy formy: polska odmiana (1 narzędzie,
     * 2–4 narzędzia oprócz 12–14, reszta narzędzi). Dwie formy: jeden i wiele.
     *
     * @param array<int,string> $forms
     */
    private static function plural(int $n, array $forms): string
    {
        $forms = array_values(array_map('trim', $forms));

        if (count($forms) >= 3) {
            if ($n === 1) {
                return $forms[0];
            }

            $d = $n % 10;
            $dd = $n % 100;

            return ($d >= 2 && $d <= 4 && ($dd < 12 || $dd > 14)) ? $forms[1] : $forms[2];
        }

        if (count($forms) === 2) {
            return $n === 1 ? $forms[0] : $forms[1];
        }

        return $forms[0] ?? '';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
