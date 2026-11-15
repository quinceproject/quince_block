<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Block\Controller\Admin;

use PrestaShopBundle\Controller\Admin\PrestaShopAdminController;
use Quince\Block\Front\BlockCache;
use Quince\Block\Storage\RuleRepository;
use Quince\Block\Tools\RuleNormalizer;
use Quince\Block\Ui\PanelNavigation;
use Quince\Block\Ui\RuleSummary;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Wspólna podstawa kontrolerów panelu.
 *
 * Daje powłokę z bocznym paskiem modułu, odpowiedzi JSON w jednym kształcie,
 * sprawdzenie CSRF, listę sklepów z kontekstu pracownika i języki. Dziedziczy po
 * klasie rdzenia, nie po kontrolerze silnika: silnik dokleja do szablonu własny
 * pasek, a moduł ma swój.
 */
abstract class BlockBaseController extends PrestaShopAdminController
{
    public const DOMAIN = 'Modules.Quinceblock.Admin';

    /** Token wywołań z JS (nagłówek X-CSRF-Token), wystawiany na `.qc-root`. */
    public const CSRF_PANEL = 'quince_block_panel';

    protected const TEMPLATES = '@Modules/quince_block/views/templates/admin/';

    /**
     * Renderuje szablon w powłoce panelu.
     *
     * @param string $template ścieżka względem `views/templates/admin/`
     * @param string $navKey klucz pozycji bocznego paska
     * @param array<string,mixed> $data zmienne szablonu
     */
    protected function renderPanel(string $template, string $navKey, array $data = []): Response
    {
        $navigation = new PanelNavigation();
        $title = (string) ($data['layoutTitle'] ?? ($navigation->label($navKey) ?: 'Blocks'));

        $shell = [
            // PrestaShop wstawia layoutTitle do nagłówka strony bez tłumaczenia.
            'layoutTitle' => $this->t($title),
            'nav' => $navigation->items(),
            'navKey' => $navKey,
            'navTitle' => $navigation->label($navKey) ?: $title,
            'qcVersion' => $this->assetVersion(),
            'multistore' => $this->isMultistore(),
            'contextLabel' => $this->contextLabel(),
        ];

        // Zmienne powłoki wygrywają z danymi ekranu.
        return $this->render(self::TEMPLATES . $template, $shell + $data);
    }

    /** @param array<string,mixed> $data */
    protected function ok(array $data = []): JsonResponse
    {
        return new JsonResponse(['ok' => true] + $data);
    }

    protected function fail(string $message, int $code = 400): JsonResponse
    {
        return new JsonResponse(['ok' => false, 'error' => $message], $code);
    }

    /**
     * Formularz niesie pole `_csrf_token` z tokenem trasy, skrypt nagłówek
     * X-CSRF-Token z tokenem trasy albo panelu.
     */
    protected function csrfValid(Request $request, string $routeName): bool
    {
        $field = $request->request->get('_csrf_token');

        if (is_string($field) && $field !== '') {
            return $this->isCsrfTokenValid($routeName, $field);
        }

        $header = (string) $request->headers->get('X-CSRF-Token', '');

        if ($header === '') {
            return false;
        }

        return $this->isCsrfTokenValid($routeName, $header) || $this->isCsrfTokenValid(self::CSRF_PANEL, $header);
    }

    /** Wspólny komunikat o złym tokenie dla akcji formularzowych. */
    protected function csrfFailed(string $route, array $params = []): Response
    {
        $this->addFlash('error', $this->t('Invalid security token, please reload the page and try again.'));

        return $this->redirectToRoute($route, $params);
    }

    /**
     * Dane żądania POST: JSON ze skryptu albo pola formularza.
     *
     * @return array<string,mixed>
     */
    protected function payload(Request $request): array
    {
        if (str_contains((string) $request->headers->get('Content-Type', ''), 'json')) {
            $data = json_decode((string) $request->getContent(), true);

            return is_array($data) ? $data : [];
        }

        return $request->request->all();
    }

    /** Tłumaczenie w domenie modułu. Dane w `$params` (`%nazwa%`). */
    protected function t(string $text, array $params = []): string
    {
        return $this->trans($text, $params, self::DOMAIN);
    }

    /**
     * Sklepy z bieżącego kontekstu pracownika (przełącznik sklepu w panelu).
     * Zapis treści i przypisań do sklepów dotyczy tylko ich; pozostałe sklepy
     * zostają nietknięte.
     *
     * @return int[]
     */
    protected function contextShopIds(): array
    {
        return array_values(array_map('intval', \Shop::getContextListShopID()));
    }

    /** @return array<int,array{id:int,name:string}> sklepy z kontekstu, z nazwami */
    protected function contextShops(): array
    {
        $out = [];

        foreach ($this->contextShopIds() as $idShop) {
            $out[] = ['id' => $idShop, 'name' => $this->shopName($idShop)];
        }

        return $out;
    }

    protected function shopName(int $idShop): string
    {
        $shop = \Shop::getShop($idShop);

        return is_array($shop) ? (string) $shop['name'] : '#' . $idShop;
    }

    /** Sklep, w którego kontekście liczymy odczyt (ustawienia, linki): bieżący albo domyślny. */
    protected function currentShopId(): int
    {
        if (\Shop::getContext() === \Shop::CONTEXT_SHOP) {
            return (int) \Shop::getContextShopID();
        }

        return $this->contextShopIds()[0] ?? (int) \Configuration::get('PS_SHOP_DEFAULT');
    }

    protected function isMultistore(): bool
    {
        return \Shop::isFeatureActive();
    }

    /**
     * Języki sklepu do pól treści. Domyślny język sklepu otwiera się pierwszy.
     *
     * @return array<int,array{id_lang:int,name:string,iso_code:string,default:bool}>
     */
    protected function languages(): array
    {
        $out = [];
        $default = (int) \Configuration::get('PS_LANG_DEFAULT');

        foreach (\Language::getLanguages(false) as $language) {
            $out[] = [
                'id_lang' => (int) $language['id_lang'],
                'name' => (string) $language['name'],
                'iso_code' => (string) $language['iso_code'],
                'default' => (int) $language['id_lang'] === $default,
            ];
        }

        return $out;
    }

    protected function employeeId(): int
    {
        $employee = \Context::getContext()->employee;

        return $employee ? (int) $employee->id : 0;
    }

    protected function idLang(): int
    {
        return (int) \Context::getContext()->language->id;
    }

    /**
     * Czyści cache wyników frontu po każdej zmianie, która może zmienić to, co
     * widzi odwiedzający. Klasa należy do części frontu; brak klasy nie może
     * przerwać zapisu w panelu.
     */
    protected function clearFrontCache(): void
    {
        if (class_exists(BlockCache::class)) {
            BlockCache::clear();
        }
    }

    /**
     * Reguły bloku do listy, z czytelnym opisem (nazwy zamiast numerów).
     *
     * @return array<int,array<string,mixed>>
     */
    protected function rulesView(RuleRepository $rules, RuleNormalizer $normalizer, RuleSummary $summary, int $idBlock): array
    {
        $definitions = $normalizer->definitions();
        $idLang = $this->idLang();
        $idShop = $this->currentShopId();
        $out = [];

        foreach ($rules->forBlock($idBlock) as $rule) {
            $definition = $definitions[$rule['type']] ?? null;
            $out[] = $rule + [
                'label' => $definition ? (string) ($definition['label'] ?? $rule['type']) : $rule['type'],
                'summary' => $definition
                    ? $summary->describe($definition, $rule['value'], $idLang, $idShop, fn (string $s): string => $this->t($s))
                    : $this->t('Unknown rule type, ignored in the shop.'),
            ];
        }

        return $out;
    }

    /** Rejestracja modułu na hooku, żeby rdzeń wywołał go w tym miejscu strony. */
    protected function registerModuleHook(string $hookName): bool
    {
        $module = \Module::getInstanceByName('quince_block');

        return $module instanceof \Module && $module->registerHook($hookName);
    }

    protected function unregisterModuleHook(string $hookName): void
    {
        $module = \Module::getInstanceByName('quince_block');

        if ($module instanceof \Module) {
            $module->unregisterHook($hookName);
        }
    }

    /** Nazwa kontekstu nad paskiem: sklep, grupa albo wszystkie sklepy. */
    private function contextLabel(): string
    {
        if (!$this->isMultistore()) {
            return '';
        }

        if (\Shop::getContext() === \Shop::CONTEXT_SHOP) {
            return $this->shopName((int) \Shop::getContextShopID());
        }

        if (\Shop::getContext() === \Shop::CONTEXT_GROUP) {
            $group = new \ShopGroup((int) \Shop::getContextShopGroupID());

            return (string) $group->name;
        }

        return $this->t('All shops');
    }

    /**
     * Znacznik wersji przy plikach CSS i JS: wersja modułu plus czas zmiany
     * najświeższego pliku. Sama wersja trzymałaby stary arkusz w przeglądarce
     * do następnego wydania.
     */
    private function assetVersion(): string
    {
        $module = \Module::getInstanceByName('quince_block');
        $version = $module instanceof \Module ? (string) $module->version : '0.1.0';
        $newest = 0;

        foreach (['css', 'js'] as $ext) {
            foreach (glob(_PS_MODULE_DIR_ . 'quince_block/views/' . $ext . '/*.' . $ext) ?: [] as $file) {
                $newest = max($newest, (int) filemtime($file));
            }
        }

        return $version . '.' . $newest;
    }
}
