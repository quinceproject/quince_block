<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Block\Front;

use ArrayAccess;
use Category;
use Product;
use Tools;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Miejsce, w którym blok się pokazuje: produkt, kategoria listingu, marka,
 * dostawca, rodzaj strony, fraza wyszukiwania i adres.
 *
 * Przy odświeżaniu AJAX-em żądanie przychodzi do kontrolera modułu, który nie
 * wie, na jakiej stronie stoi blok. Kontekst jedzie więc w atrybucie bloku
 * z podpisem: klient nie podstawi innego produktu, żeby obejść regułę kategorii.
 */
final class PageContext
{
    public function __construct(
        public readonly int $idProduct = 0,
        public readonly int $idProductAttribute = 0,
        public readonly int $idCategory = 0,
        public readonly int $idManufacturer = 0,
        public readonly int $idSupplier = 0,
        public readonly string $page = 'other',
        public readonly string $search = '',
        public readonly string $url = '',
    ) {
    }

    /**
     * Kontekst z parametrów hooka i z kontrolera strony.
     *
     * Produkt z hooka może być tablicą, `ProductLazyArray` albo `Product`
     * (miniatura na liście produktów). Bez niego produkt bierzemy ze strony
     * produktu. Kategorię z hooka albo ze strony kategorii.
     *
     * @param array<string,mixed> $params
     * @param mixed $controller
     */
    public static function fromRequest(array $params, $controller): self
    {
        $idProduct = self::idFrom($params['product'] ?? null, ['id_product', 'id']);
        $idAttribute = self::idFrom($params['product'] ?? null, ['id_product_attribute']);

        if ($idProduct === 0 && isset($params['id_product'])) {
            $idProduct = (int) $params['id_product'];
            $idAttribute = (int) ($params['id_product_attribute'] ?? 0);
        }

        if ($idProduct === 0 && $controller instanceof \ProductController) {
            $product = $controller->getProduct();
            $idProduct = $product ? (int) $product->id : 0;
            $idAttribute = (int) Tools::getValue('id_product_attribute', 0);
        }

        $idCategory = self::idFrom($params['category'] ?? null, ['id_category', 'id']);

        if ($idCategory === 0 && isset($params['id_category'])) {
            $idCategory = (int) $params['id_category'];
        }

        if ($idCategory === 0 && $controller instanceof \CategoryController) {
            $idCategory = (int) $controller->getCategory()->id;
        }

        $idManufacturer = 0;
        $idSupplier = 0;

        if ($controller instanceof \ManufacturerController) {
            $manufacturer = $controller->getManufacturer();
            $idManufacturer = $manufacturer ? (int) $manufacturer->id : 0;
        }

        if ($controller instanceof \SupplierController) {
            $supplier = $controller->getSupplier();
            $idSupplier = $supplier ? (int) $supplier->id : 0;
        }

        $phpSelf = is_object($controller) && isset($controller->php_self) ? (string) $controller->php_self : '';
        $page = in_array($phpSelf, \Quince\Block\Rule\RuleTypes::PAGES, true) ? $phpSelf : 'other';
        $search = $page === 'search' ? (string) Tools::getValue('s', Tools::getValue('search_query', '')) : '';

        return new self(
            max(0, $idProduct),
            max(0, $idAttribute),
            max(0, $idCategory),
            $idManufacturer,
            $idSupplier,
            $page,
            Tools::substr(trim($search), 0, 255),
            Tools::substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 1024)
        );
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $page = (string) ($data['page'] ?? 'other');

        return new self(
            max(0, (int) ($data['id_product'] ?? 0)),
            max(0, (int) ($data['id_product_attribute'] ?? 0)),
            max(0, (int) ($data['id_category'] ?? 0)),
            max(0, (int) ($data['id_manufacturer'] ?? 0)),
            max(0, (int) ($data['id_supplier'] ?? 0)),
            in_array($page, \Quince\Block\Rule\RuleTypes::PAGES, true) ? $page : 'other',
            (string) ($data['search'] ?? ''),
            (string) ($data['url'] ?? '')
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'id_product' => $this->idProduct,
            'id_product_attribute' => $this->idProductAttribute,
            'id_category' => $this->idCategory,
            'id_manufacturer' => $this->idManufacturer,
            'id_supplier' => $this->idSupplier,
            'page' => $this->page,
            'search' => $this->search,
            'url' => $this->url,
        ];
    }

    /** Wartość parametru `utm_<nazwa>` z adresu strony. */
    public function urlParam(string $name): string
    {
        $query = (string) parse_url($this->url, PHP_URL_QUERY);

        if ($query === '') {
            return '';
        }

        parse_str($query, $values);

        return is_scalar($values[$name] ?? null) ? (string) $values[$name] : '';
    }

    /**
     * Kontekst z podpisem do atrybutu `data-qb-ctx`.
     *
     * Podpis obejmuje blok i hook, w którym blok stoi (pusty dla krótkiego kodu).
     * Znacznik z jednego bloku nie pozwala więc odświeżyć innego, a blok wstawiany
     * krótkim kodem da się pobrać tylko z kontekstem strony, na której go wstawiono.
     */
    public function sign(int $idBlock, string $hook): string
    {
        $json = (string) json_encode([
            $this->idProduct, $this->idProductAttribute, $this->idCategory, $this->idManufacturer,
            $this->idSupplier, $this->page, $this->search, $this->url,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $payload = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        return $payload . '.' . self::mac($payload, self::slot($idBlock, $hook));
    }

    public static function verify(string $token, int $idBlock, string $hook): ?self
    {
        $parts = explode('.', $token, 2);

        if (count($parts) !== 2 || strlen($token) > 4096
            || !hash_equals(self::mac($parts[0], self::slot($idBlock, $hook)), $parts[1])) {
            return null;
        }

        $data = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true);

        if (!is_array($data) || count($data) !== 8) {
            return null;
        }

        return self::fromArray([
            'id_product' => $data[0],
            'id_product_attribute' => $data[1],
            'id_category' => $data[2],
            'id_manufacturer' => $data[3],
            'id_supplier' => $data[4],
            'page' => $data[5],
            'search' => $data[6],
            'url' => $data[7],
        ]);
    }

    /** Cel podpisu kontekstu: blok i miejsce, w którym stoi. */
    private static function slot(int $idBlock, string $hook): string
    {
        return 'ctx|' . $idBlock . '|' . strtolower($hook);
    }

    /** Klucz podpisu wspólny dla kontekstu i statystyk, z rozdzieleniem celów. */
    public static function mac(string $payload, string $purpose = 'ctx'): string
    {
        return substr(hash_hmac('sha256', $purpose . '|' . $payload, 'quince_block|' . _COOKIE_KEY_), 0, 32);
    }

    /**
     * @param mixed $source
     * @param array<int,string> $keys
     */
    private static function idFrom($source, array $keys): int
    {
        if ($source instanceof Product || $source instanceof Category) {
            return in_array('id', $keys, true) || in_array('id_product', $keys, true) || in_array('id_category', $keys, true)
                ? (int) $source->id
                : (int) ($source->{$keys[0]} ?? 0);
        }

        if (is_array($source) || $source instanceof ArrayAccess) {
            foreach ($keys as $key) {
                if (isset($source[$key]) && (int) $source[$key] > 0) {
                    return (int) $source[$key];
                }
            }
        }

        return 0;
    }
}
