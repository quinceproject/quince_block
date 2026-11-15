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

use Cart;
use Category;
use Configuration;
use Context;
use Hook;
use Manufacturer;
use Product;
use Quince\Block\Front\PageContext;
use StockAvailable;
use Tools;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Placeholdery `{{...}}` i bloki warunkowe `{{#if_x}}...{{/if_x}}` w treści bloku.
 *
 * Nazwy jak w WiseBlock. Liczone leniwie: treść bez `{{` wychodzi bez zmian,
 * a z treścią liczymy tylko grupy, których tokeny w niej występują (produkt,
 * koszyk, darmowa dostawa). Wartości z kontekstu są escapowane. Inne moduły
 * dokładają tokeny przez hook `actionQuinceBlockRegisterPlaceholders`
 * i same odpowiadają za escapowanie swoich wartości; wbudowanych nie nadpiszą.
 */
final class Placeholders
{
    public const HOOK = 'actionQuinceBlockRegisterPlaceholders';

    private const DOMAIN_ADMIN = 'Modules.Quinceblock.Admin';
    private const DOMAIN_SHOP = 'Modules.Quinceblock.Shop';

    private const GENERAL = ['shop_name', 'customer_name', 'search_query'];
    private const PRODUCT = [
        'product_name', 'reference', 'ean13', 'manufacturer', 'category_name', 'category_description',
        'product_url', 'add_to_cart_url', 'price', 'price_without_tax', 'stock_status', 'stock_quantity', 'weight',
    ];
    private const CART = ['cart_total', 'cart_total_with_shipping', 'cart_products_count'];
    private const SHIPPING = [
        'free_shipping_threshold', 'free_shipping_remaining', 'free_shipping_remaining_raw',
        'free_shipping_progress', 'free_shipping_achieved',
    ];
    private const CONDITIONS = [
        'if_free_shipping_not_achieved', 'if_free_shipping_achieved', 'if_in_stock', 'if_out_of_stock',
        'if_low_stock', 'if_on_sale', 'if_has_manufacturer', 'if_new_product', 'if_cart_empty',
        'if_cart_not_empty', 'if_logged_in', 'if_guest',
    ];

    /** @var array<string,array<string,mixed>> dane produktów w tym żądaniu */
    private static array $products = [];

    /** @var array<string,mixed> koszyk i dostawa w tym żądaniu */
    private static array $cart = [];

    /** @var array<string,array<string,string>> tokeny innych modułów w tym żądaniu */
    private static array $external = [];

    private Context $context;

    public function __construct(?Context $context = null)
    {
        $this->context = $context ?? Context::getContext();
    }

    /**
     * Lista tokenów dla panelu.
     *
     * @return array<int,array{token:string,label:string,group:string}>
     */
    public static function catalogue(): array
    {
        $tr = Context::getContext()->getTranslator();
        $t = static fn (string $text): string => $tr->trans($text, [], self::DOMAIN_ADMIN);

        $groups = [
            $t('Shop and customer') => [
                'shop_name' => $t('Shop name'),
                'customer_name' => $t('Customer first name (signed in only)'),
                'search_query' => $t('Search phrase'),
            ],
            $t('Product') => [
                'product_name' => $t('Product name'),
                'reference' => $t('Reference'),
                'ean13' => $t('EAN-13'),
                'manufacturer' => $t('Brand'),
                'category_name' => $t('Default category name'),
                'category_description' => $t('Default category description (plain text)'),
                'product_url' => $t('Product address'),
                'add_to_cart_url' => $t('Add to cart address'),
                'price' => $t('Price tax incl.'),
                'price_without_tax' => $t('Price tax excl.'),
                'stock_status' => $t('Stock status'),
                'stock_quantity' => $t('Stock quantity'),
                'weight' => $t('Weight'),
            ],
            $t('Cart') => [
                'cart_total' => $t('Cart total without shipping'),
                'cart_total_with_shipping' => $t('Cart total with shipping'),
                'cart_products_count' => $t('Number of products in cart'),
            ],
            $t('Free shipping') => [
                'free_shipping_threshold' => $t('Free shipping threshold'),
                'free_shipping_remaining' => $t('Amount missing to free shipping'),
                'free_shipping_remaining_raw' => $t('Amount missing to free shipping, as a number'),
                'free_shipping_progress' => $t('Progress to free shipping in percent'),
                'free_shipping_achieved' => $t('Free shipping reached (1 or 0)'),
            ],
        ];

        $out = [];

        foreach ($groups as $group => $tokens) {
            foreach ($tokens as $name => $label) {
                $out[] = ['token' => '{{' . $name . '}}', 'label' => $label, 'group' => $group];
            }
        }

        $conditions = [
            'if_free_shipping_not_achieved' => $t('Shown until free shipping is reached'),
            'if_free_shipping_achieved' => $t('Shown when free shipping is reached'),
            'if_in_stock' => $t('Shown when the product is in stock'),
            'if_out_of_stock' => $t('Shown when the product is out of stock'),
            'if_low_stock' => $t('Shown when the product stock is low'),
            'if_on_sale' => $t('Shown when the product has a discount'),
            'if_has_manufacturer' => $t('Shown when the product has a brand'),
            'if_new_product' => $t('Shown when the product is new'),
            'if_cart_empty' => $t('Shown when the cart is empty'),
            'if_cart_not_empty' => $t('Shown when the cart is not empty'),
            'if_logged_in' => $t('Shown to signed in customers'),
            'if_guest' => $t('Shown to visitors who are not signed in'),
        ];

        foreach ($conditions as $name => $label) {
            $out[] = ['token' => '{{#' . $name . '}}...{{/' . $name . '}}', 'label' => $label, 'group' => $t('Conditions')];
        }

        return $out;
    }

    public static function reset(): void
    {
        self::$products = [];
        self::$cart = [];
        self::$external = [];
    }

    public function render(string $html, PageContext $page): string
    {
        if (strpos($html, '{{') === false) {
            return $html;
        }

        if (!preg_match_all('/\{\{([#\/]?)([a-z0-9_]+)\}\}/', $html, $m)) {
            return $html;
        }

        $names = array_unique($m[2]);
        $values = [];

        if (array_intersect($names, self::GENERAL)) {
            $values += $this->general($page);
        }

        $needsProduct = array_intersect($names, array_merge(self::PRODUCT, ['if_in_stock', 'if_out_of_stock', 'if_low_stock', 'if_on_sale', 'if_has_manufacturer', 'if_new_product']));
        $product = $needsProduct ? $this->product($page) : null;

        if (array_intersect($names, self::PRODUCT)) {
            $values += $this->productValues($product);

            // Bez produktu (lista kategorii) nazwa i opis kategorii idą z oglądanej
            // kategorii, a nie zostają puste.
            if ($product === null && $page->idCategory > 0) {
                $category = new Category($page->idCategory, (int) $this->context->language->id);

                if ($category->id) {
                    $values['{{category_name}}'] = self::e((string) $category->name);
                    $values['{{category_description}}'] = self::e(trim(html_entity_decode(strip_tags((string) $category->description), ENT_QUOTES, 'UTF-8')));
                }
            }
        }

        if (array_intersect($names, self::CART) || in_array('if_cart_empty', $names, true) || in_array('if_cart_not_empty', $names, true)) {
            $values += $this->cartValues();
        }

        if (array_intersect($names, self::SHIPPING) || in_array('if_free_shipping_achieved', $names, true) || in_array('if_free_shipping_not_achieved', $names, true)) {
            $values += $this->shippingValues();
        }

        foreach (array_intersect($names, self::CONDITIONS) as $condition) {
            $html = $this->condition($html, $condition, $this->conditionHolds($condition, $product));
        }

        $known = array_merge(self::GENERAL, self::PRODUCT, self::CART, self::SHIPPING, self::CONDITIONS);
        $unknown = array_diff($names, $known);

        if ($unknown !== []) {
            foreach ($this->external($page) as $token => $value) {
                if (!isset($values[$token]) && is_scalar($value)) {
                    $values[$token] = (string) $value;
                }
            }
        }

        return strtr($html, $values);
    }

    /** @return array<string,string> */
    private function general(PageContext $page): array
    {
        $customer = $this->context->customer;

        return [
            '{{shop_name}}' => self::e((string) Configuration::get('PS_SHOP_NAME')),
            '{{customer_name}}' => ($customer && $customer->isLogged()) ? self::e((string) $customer->firstname) : '',
            '{{search_query}}' => self::e($page->search),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function product(PageContext $page): ?array
    {
        $id = $page->idProduct;

        if ($id <= 0) {
            return null;
        }

        $idLang = (int) $this->context->language->id;
        $idShop = (int) $this->context->shop->id;
        $ipa = $page->idProductAttribute;
        $key = $id . '-' . $ipa . '-' . $idLang;

        if (isset(self::$products[$key])) {
            return self::$products[$key] ?: null;
        }

        $product = new Product($id, true, $idLang, $idShop);

        if (!$product->id) {
            self::$products[$key] = [];

            return null;
        }

        $quantity = (int) StockAvailable::getQuantityAvailableByProduct($id, $ipa > 0 ? $ipa : null, $idShop);
        $price = (float) Product::getPriceStatic($id, true, $ipa > 0 ? $ipa : null);
        $regular = (float) Product::getPriceStatic($id, true, $ipa > 0 ? $ipa : null, 6, null, false, false);
        $category = $product->id_category_default ? new Category((int) $product->id_category_default, $idLang) : null;

        return self::$products[$key] = [
            'product' => $product,
            'quantity' => $quantity,
            'price' => $price,
            'price_without_tax' => (float) Product::getPriceStatic($id, false, $ipa > 0 ? $ipa : null),
            'on_sale' => $regular > 0 && $price < $regular,
            'is_new' => (bool) $product->isNew(),
            'category_name' => $category && $category->id ? (string) $category->name : '',
            'category_description' => $category && $category->id ? (string) $category->description : '',
            'manufacturer' => $product->id_manufacturer ? (string) Manufacturer::getNameById((int) $product->id_manufacturer) : '',
            'ipa' => $ipa,
        ];
    }

    /**
     * Bez produktu tokeny produktu znikają (WiseBlock zostawiał je dosłownie w HTML).
     *
     * @param array<string,mixed>|null $data
     *
     * @return array<string,string>
     */
    private function productValues(?array $data): array
    {
        if ($data === null) {
            return array_fill_keys(array_map(static fn (string $n): string => '{{' . $n . '}}', self::PRODUCT), '');
        }

        /** @var Product $product */
        $product = $data['product'];
        $link = $this->context->link;
        $weight = (float) $product->weight;

        return [
            '{{product_name}}' => self::e((string) $product->name),
            '{{reference}}' => self::e((string) $product->reference),
            '{{ean13}}' => self::e((string) $product->ean13),
            '{{manufacturer}}' => self::e($data['manufacturer']),
            '{{category_name}}' => self::e($data['category_name']),
            '{{category_description}}' => self::e(trim(html_entity_decode(strip_tags($data['category_description']), ENT_QUOTES, 'UTF-8'))),
            '{{product_url}}' => self::e($link->getProductLink($product, null, null, null, null, null, (int) $data['ipa'])),
            '{{add_to_cart_url}}' => self::e($link->getPageLink('cart', null, (int) $this->context->language->id, [
                'add' => 1,
                'id_product' => (int) $product->id,
                'id_product_attribute' => (int) $data['ipa'],
                'token' => Tools::getToken(false),
            ])),
            '{{price}}' => self::e($this->price((float) $data['price'])),
            '{{price_without_tax}}' => self::e($this->price((float) $data['price_without_tax'])),
            '{{stock_status}}' => self::e($data['quantity'] > 0
                ? $this->context->getTranslator()->trans('In stock', [], self::DOMAIN_SHOP)
                : $this->context->getTranslator()->trans('Out of stock', [], self::DOMAIN_SHOP)),
            '{{stock_quantity}}' => (string) (int) $data['quantity'],
            '{{weight}}' => $weight > 0 ? self::e(number_format($weight, 2) . ' ' . Configuration::get('PS_WEIGHT_UNIT')) : '',
        ];
    }

    /** @return array<string,string> */
    private function cartValues(): array
    {
        $cart = $this->cartState();

        return [
            '{{cart_total}}' => self::e($this->price($cart['total'])),
            '{{cart_total_with_shipping}}' => self::e($this->price($cart['total_with_shipping'])),
            '{{cart_products_count}}' => (string) $cart['count'],
        ];
    }

    /** @return array<string,string> */
    private function shippingValues(): array
    {
        $s = $this->shippingState();

        return [
            '{{free_shipping_threshold}}' => $s['threshold'] > 0 ? self::e($this->price($s['threshold'])) : '',
            '{{free_shipping_remaining}}' => $s['remaining'] > 0 ? self::e($this->price($s['remaining'])) : '',
            '{{free_shipping_remaining_raw}}' => number_format($s['remaining'], 2, '.', ''),
            '{{free_shipping_progress}}' => (string) (int) $s['progress'],
            '{{free_shipping_achieved}}' => $s['achieved'] ? '1' : '0',
        ];
    }

    /**
     * @param array<string,mixed>|null $product
     */
    private function conditionHolds(string $condition, ?array $product): bool
    {
        switch ($condition) {
            case 'if_free_shipping_not_achieved':
                $s = $this->shippingState();

                return $s['threshold'] > 0 && !$s['achieved'];
            case 'if_free_shipping_achieved':
                return $this->shippingState()['achieved'];
            case 'if_cart_empty':
                return $this->cartState()['count'] <= 0;
            case 'if_cart_not_empty':
                return $this->cartState()['count'] > 0;
            case 'if_logged_in':
                return (bool) ($this->context->customer && $this->context->customer->isLogged());
            case 'if_guest':
                return !($this->context->customer && $this->context->customer->isLogged());
        }

        // Warunki produktowe bez produktu: blok warunkowy wypada.
        if ($product === null) {
            return false;
        }

        switch ($condition) {
            case 'if_in_stock':
                return $product['quantity'] > 0;
            case 'if_out_of_stock':
                return $product['quantity'] <= 0;
            case 'if_low_stock':
                $threshold = (int) Configuration::get('QUINCE_BLOCK_LOW_STOCK');
                $threshold = $threshold > 0 ? $threshold : 5;

                return $product['quantity'] > 0 && $product['quantity'] <= $threshold;
            case 'if_on_sale':
                return (bool) $product['on_sale'];
            case 'if_has_manufacturer':
                return (int) $product['product']->id_manufacturer > 0;
            case 'if_new_product':
                return (bool) $product['is_new'];
        }

        return false;
    }

    private function condition(string $html, string $name, bool $keep): string
    {
        $open = '{{#' . $name . '}}';
        $close = '{{/' . $name . '}}';

        if ($keep) {
            return str_replace([$open, $close], '', $html);
        }

        return (string) preg_replace('/' . preg_quote($open, '/') . '.*?' . preg_quote($close, '/') . '/s', '', $html);
    }

    /** @return array{total:float,total_with_shipping:float,count:int} */
    private function cartState(): array
    {
        if (!isset(self::$cart['state'])) {
            $cart = $this->context->cart;
            $has = $cart && $cart->id;

            self::$cart['state'] = [
                'total' => $has ? (float) $cart->getOrderTotal(true, Cart::BOTH_WITHOUT_SHIPPING) : 0.0,
                'total_with_shipping' => $has ? (float) $cart->getOrderTotal(true) : 0.0,
                'count' => $has ? (int) $cart->nbProducts() : 0,
            ];
        }

        return self::$cart['state'];
    }

    /**
     * Próg darmowej dostawy jak w silniku Quince (`PS_SHIPPING_FREE_PRICE`
     * w walucie klienta, wartość koszyka brutto bez dostawy), żeby blok mówił
     * to samo co szuflada koszyka. Przy pustym koszyku próg jest znany,
     * a do progu brakuje całej kwoty.
     *
     * @return array{threshold:float,remaining:float,progress:int,achieved:bool}
     */
    private function shippingState(): array
    {
        if (isset(self::$cart['shipping'])) {
            return self::$cart['shipping'];
        }

        $state = ['threshold' => 0.0, 'remaining' => 0.0, 'progress' => 0, 'achieved' => false];

        if (class_exists(\Quince\Engine\Cart\FreeShipping::class)) {
            $engine = \Quince\Engine\Cart\FreeShipping::forContext($this->context);

            if ($engine !== null) {
                return self::$cart['shipping'] = [
                    'threshold' => (float) $engine['threshold'],
                    'remaining' => (float) $engine['missing'],
                    'progress' => (int) $engine['percent'],
                    'achieved' => (bool) $engine['reached'],
                ];
            }
        }

        $threshold = (float) Configuration::get('PS_SHIPPING_FREE_PRICE');
        $cart = $this->context->cart;
        $virtual = $cart && $cart->id && $cart->nbProducts() && $cart->isVirtualCart();

        if ($threshold > 0 && !$virtual) {
            $threshold = (float) Tools::convertPrice($threshold, $this->context->currency);
            $total = $this->cartState()['total'];
            $state = [
                'threshold' => $threshold,
                'remaining' => max(0.0, $threshold - $total),
                'progress' => (int) min(100, floor($total / $threshold * 100)),
                'achieved' => $total >= $threshold,
            ];
        }

        return self::$cart['shipping'] = $state;
    }

    /**
     * @return array<string,mixed>
     */
    private function external(PageContext $page): array
    {
        $cart = $this->context->cart;
        $key = $page->idProduct . '-' . (int) $this->context->language->id . '-' . (int) ($cart->id ?? 0);

        if (isset(self::$external[$key])) {
            return self::$external[$key];
        }

        $merged = [];

        if (Hook::getIdByName(self::HOOK)) {
            $results = Hook::exec(self::HOOK, [
                'id_product' => $page->idProduct,
                'id_product_attribute' => $page->idProductAttribute,
                'id_lang' => (int) $this->context->language->id,
                'id_shop' => (int) $this->context->shop->id,
                'id_cart' => $cart ? (int) $cart->id : null,
                'id_customer' => (int) ($this->context->customer->id ?? 0),
                'customer' => $this->context->customer,
                'page' => $page->toArray(),
            ], null, true);

            foreach (is_array($results) ? $results : [] as $moduleResult) {
                foreach (is_array($moduleResult) ? $moduleResult : [] as $token => $value) {
                    if (is_string($token) && preg_match('/^\{\{[a-z0-9_]+\}\}$/', $token) === 1 && !isset($merged[$token])) {
                        $merged[$token] = $value;
                    }
                }
            }
        }

        return self::$external[$key] = $merged;
    }

    private function price(float $amount): string
    {
        $locale = $this->context->getCurrentLocale();
        $iso = (string) ($this->context->currency->iso_code ?? '');

        if ($locale === null || $iso === '') {
            return number_format($amount, 2, '.', ' ');
        }

        return (string) $locale->formatPrice($amount, $iso);
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
