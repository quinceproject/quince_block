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

use Address;
use Cart;
use Context;
use Customer;
use Db;
use Quince\Block\Front\Consent;
use Quince\Block\Front\PageContext;
use Tools;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Fakty, z którymi reguły porównują swoje wartości.
 *
 * Każdy fakt liczony dopiero przy pierwszym pytaniu i pamiętany do końca
 * żądania: dane produktu per produkt (lista produktów pyta o ten sam produkt
 * w kilku hookach), dane koszyka i klienta raz.
 */
final class Facts
{
    /** @var array<int,array<string,mixed>> dane produktów w tym żądaniu */
    private static array $products = [];

    /** @var array<int,array<int,int>> przodkowie kategorii listingu */
    private static array $categoryTrees = [];

    /** @var array<string,mixed> fakty koszyka i klienta w tym żądaniu */
    private static array $shared = [];

    private Context $context;

    public function __construct(public readonly PageContext $page, ?Context $context = null)
    {
        $this->context = $context ?? Context::getContext();
    }

    public static function reset(): void
    {
        self::$products = [];
        self::$categoryTrees = [];
        self::$shared = [];
    }

    public function hasProduct(): bool
    {
        return $this->page->idProduct > 0;
    }

    /**
     * Kategorie produktu: przypisane wprost i z przodkami.
     *
     * @return array{direct:array<int,int>,tree:array<int,int>}
     */
    public function productCategories(): array
    {
        $data = $this->product();

        return ['direct' => $data['direct'], 'tree' => $data['tree']];
    }

    /**
     * Kategoria listingu z przodkami (sama na początku).
     *
     * @return array<int,int>
     */
    public function listingCategoryTree(): array
    {
        $id = $this->page->idCategory;

        if ($id <= 0) {
            return [];
        }

        if (!isset(self::$categoryTrees[$id])) {
            $rows = Db::getInstance()->executeS(
                'SELECT c2.`id_category`
                   FROM `' . _DB_PREFIX_ . 'category` c
                  INNER JOIN `' . _DB_PREFIX_ . 'category` c2
                     ON c2.`nleft` <= c.`nleft` AND c2.`nright` >= c.`nright`
                  WHERE c.`id_category` = ' . (int) $id
            ) ?: [];

            self::$categoryTrees[$id] = array_map('intval', array_column($rows, 'id_category'));

            if (!in_array($id, self::$categoryTrees[$id], true)) {
                array_unshift(self::$categoryTrees[$id], $id);
            }
        }

        return self::$categoryTrees[$id];
    }

    public function manufacturerId(): int
    {
        return $this->hasProduct() ? (int) $this->product()['id_manufacturer'] : $this->page->idManufacturer;
    }

    public function supplierId(): int
    {
        return $this->hasProduct() ? (int) $this->product()['id_supplier'] : $this->page->idSupplier;
    }

    /** @return array<int,array{0:int,1:int}> pary [id_feature, id_feature_value] */
    public function features(): array
    {
        if (!$this->hasProduct()) {
            return [];
        }

        $this->product();
        $data = &self::$products[$this->page->idProduct];

        if (!isset($data['features'])) {
            $rows = Db::getInstance()->executeS(
                'SELECT `id_feature`, `id_feature_value`
                   FROM `' . _DB_PREFIX_ . 'feature_product`
                  WHERE `id_product` = ' . (int) $this->page->idProduct
            ) ?: [];

            $data['features'] = array_map(
                static fn (array $r): array => [(int) $r['id_feature'], (int) $r['id_feature_value']],
                $rows
            );
        }

        return $data['features'];
    }

    /** @return array<int,string> tagi produktu w bieżącym języku, małymi literami */
    public function tags(): array
    {
        if (!$this->hasProduct()) {
            return [];
        }

        $idLang = (int) $this->context->language->id;
        $this->product();
        $data = &self::$products[$this->page->idProduct];

        if (!isset($data['tags'][$idLang])) {
            $rows = Db::getInstance()->executeS(
                'SELECT t.`name`
                   FROM `' . _DB_PREFIX_ . 'product_tag` pt
                  INNER JOIN `' . _DB_PREFIX_ . 'tag` t ON t.`id_tag` = pt.`id_tag`
                  WHERE pt.`id_product` = ' . (int) $this->page->idProduct . '
                    AND t.`id_lang` = ' . $idLang
            ) ?: [];

            $data['tags'][$idLang] = array_map(static fn (array $r): string => mb_strtolower((string) $r['name']), $rows);
        }

        return $data['tags'][$idLang];
    }

    /** @return array<int,int> wszystkie grupy klienta; gość ma grupę gościa */
    public function groups(): array
    {
        return self::$shared['groups'] ??= array_map(
            'intval',
            Customer::getGroupsStatic((int) ($this->context->customer->id ?? 0))
        );
    }

    /** Kraj z adresu dostawy koszyka, inaczej kraj kontekstu. */
    public function countryId(): int
    {
        if (!isset(self::$shared['country'])) {
            $country = 0;
            $cart = $this->context->cart;

            if ($cart && (int) $cart->id_address_delivery > 0) {
                $address = Address::getCountryAndState((int) $cart->id_address_delivery);
                $country = is_array($address) ? (int) $address['id_country'] : 0;
            }

            self::$shared['country'] = $country > 0 ? $country : (int) ($this->context->country->id ?? 0);
        }

        return self::$shared['country'];
    }

    /** Wartość koszyka brutto bez dostawy, w walucie domyślnej sklepu. */
    public function cartTotal(): float
    {
        if (!isset(self::$shared['cart_total'])) {
            $cart = $this->context->cart;
            $total = 0.0;

            if ($cart && $cart->id) {
                $total = (float) $cart->getOrderTotal(true, Cart::BOTH_WITHOUT_SHIPPING);
                $total = (float) Tools::convertPrice($total, (int) $cart->id_currency, false);
            }

            self::$shared['cart_total'] = $total;
        }

        return self::$shared['cart_total'];
    }

    /** @return array<int,int> */
    public function cartProductIds(): array
    {
        if (!isset(self::$shared['cart_products'])) {
            $cart = $this->context->cart;
            $ids = [];

            if ($cart && $cart->id) {
                $rows = Db::getInstance()->executeS(
                    'SELECT DISTINCT `id_product` FROM `' . _DB_PREFIX_ . 'cart_product`
                      WHERE `id_cart` = ' . (int) $cart->id
                ) ?: [];
                $ids = array_map('intval', array_column($rows, 'id_product'));
            }

            self::$shared['cart_products'] = $ids;
        }

        return self::$shared['cart_products'];
    }

    public function currencyId(): int
    {
        return (int) ($this->context->currency->id ?? 0);
    }

    public function languageId(): int
    {
        return (int) ($this->context->language->id ?? 0);
    }

    public function device(): string
    {
        $device = (int) $this->context->getDevice();

        if ($device === Context::DEVICE_MOBILE) {
            return 'mobile';
        }

        return $device === Context::DEVICE_TABLET ? 'tablet' : 'desktop';
    }

    public function isLogged(): bool
    {
        return (bool) ($this->context->customer && $this->context->customer->isLogged());
    }

    /**
     * Parametr UTM z adresu strony, inaczej zapamiętany w ciasteczku.
     * Ciasteczko czytamy tylko przy zgodzie na pomiar.
     */
    public function utm(string $param): string
    {
        $value = $this->page->urlParam('utm_' . $param);

        if ($value === '' && Consent::analyticsGranted()) {
            $stored = json_decode((string) ($_COOKIE[Consent::UTM_COOKIE] ?? ''), true);
            $value = is_array($stored) && is_scalar($stored[$param] ?? null) ? (string) $stored[$param] : '';
        }

        return Tools::substr(trim($value), 0, 255);
    }

    /**
     * Dane produktu potrzebne regułom, jednym zapytaniem na produkt.
     *
     * @return array<string,mixed>
     */
    private function product(): array
    {
        $id = $this->page->idProduct;

        if ($id <= 0) {
            return ['direct' => [], 'tree' => [], 'id_manufacturer' => 0, 'id_supplier' => 0];
        }

        if (!isset(self::$products[$id]['loaded'])) {
            $p = _DB_PREFIX_;
            $row = Db::getInstance()->getRow(
                'SELECT `id_manufacturer`, `id_supplier` FROM `' . $p . 'product` WHERE `id_product` = ' . (int) $id
            ) ?: [];

            // Kategorie przypisane i ich przodkowie jednym zapytaniem po drzewie nleft/nright.
            $rows = Db::getInstance()->executeS(
                'SELECT cp.`id_category` AS `direct`, c2.`id_category` AS `ancestor`
                   FROM `' . $p . 'category_product` cp
                  INNER JOIN `' . $p . 'category` c ON c.`id_category` = cp.`id_category`
                  INNER JOIN `' . $p . 'category` c2 ON c2.`nleft` <= c.`nleft` AND c2.`nright` >= c.`nright`
                  WHERE cp.`id_product` = ' . (int) $id
            ) ?: [];

            self::$products[$id] = array_merge(self::$products[$id] ?? [], [
                'loaded' => true,
                'id_manufacturer' => (int) ($row['id_manufacturer'] ?? 0),
                'id_supplier' => (int) ($row['id_supplier'] ?? 0),
                'direct' => array_values(array_unique(array_map('intval', array_column($rows, 'direct')))),
                'tree' => array_values(array_unique(array_map('intval', array_column($rows, 'ancestor')))),
            ]);
        }

        return self::$products[$id];
    }
}
