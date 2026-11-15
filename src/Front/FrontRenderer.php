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

use Configuration;
use Context;
use Media;
use Module;
use Quince\Block\Render\Placeholders;
use Quince\Block\Render\Shortcodes;
use Quince\Block\Rule\Facts;
use Quince\Block\Rule\RuleEngine;
use Quince\Block\Rule\Schedule;
use Quince\Block\Storage\BlockReader;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Wyświetlanie bloków na froncie: hooki, kod w head i stopce, krótkie kody,
 * odświeżanie AJAX-em i podgląd.
 *
 * Jedyne wejście z pliku głównego modułu. Kolejność dla każdego bloku:
 * indeks sklepu (bez bazy, jeśli hook nie ma bloków), harmonogram, reguły,
 * dopiero potem treść (jedno zapytanie dla wszystkich bloków, które przeszły),
 * wariant A/B i placeholdery.
 */
final class FrontRenderer
{
    public const DOMAIN_SHOP = 'Modules.Quinceblock.Shop';

    /** Ile bloków przyjmuje jedno żądanie odświeżenia. */
    public const MAX_ITEMS = 50;

    /** @var array<string,string> wynik hooków w tym żądaniu */
    private static array $hookOutput = [];

    /** @var array<string,array<int,array{head_code:string,footer_code:string}>> kod bloków w tym żądaniu */
    private static array $codeBlocks = [];

    private Context $context;
    private BlockReader $reader;

    public function __construct(private Module $module)
    {
        $this->context = Context::getContext();
        $this->reader = new BlockReader();
    }

    /**
     * Bloki przypisane do hooka `displayQuinceBlock*`.
     *
     * @param array<string,mixed> $params parametry hooka (np. produkt miniatury)
     */
    public function renderHook(string $hookName, array $params): string
    {
        if (preg_match('/^[a-zA-Z0-9_]{1,64}$/', $hookName) !== 1) {
            return '';
        }

        $index = $this->reader->index($this->idShop());
        $ids = $index['hooks'][strtolower($hookName)] ?? [];

        if ($ids === []) {
            return '';
        }

        $page = PageContext::fromRequest($params, $this->context->controller);
        $memo = $this->idShop() . '|' . $this->idLang() . '|' . strtolower($hookName) . '|' . $page->sign();

        if (isset(self::$hookOutput[$memo])) {
            return self::$hookOutput[$memo];
        }

        return self::$hookOutput[$memo] = implode('', $this->renderList($ids, $hookName, $page));
    }

    /** Własny CSS sklepu i kod `<head>` bloków, które tu przechodzą reguły. */
    public function headCode(): string
    {
        $out = '';
        $css = trim(CustomCss::decode((string) Configuration::get(CustomCss::KEY)));

        if ($css !== '') {
            // Zamknięcie znacznika w treści CSS ucięłoby arkusz i otworzyło HTML.
            $out .= '<style id="quince-block-css">' . str_ireplace('</style', '<\/style', $css) . '</style>';
        }

        foreach ($this->codeBlocks() as $code) {
            $out .= $code['head_code'];
        }

        return $out;
    }

    public function footerCode(): string
    {
        $out = '';

        foreach ($this->codeBlocks() as $code) {
            $out .= $code['footer_code'];
        }

        return $out;
    }

    /**
     * Skrypt i arkusz frontu, tylko gdy w sklepie jest blok, który ich
     * potrzebuje (odświeżanie, leniwe ładowanie, test A/B, reguła UTM).
     *
     * @param mixed $controller
     */
    public function registerAssets($controller): void
    {
        if (!$controller instanceof \FrontController) {
            return;
        }

        $index = $this->reader->index($this->idShop());

        if (!$index['js']) {
            return;
        }

        $path = 'modules/' . $this->module->name . '/views/';
        $controller->registerStylesheet('quince-block-front', $path . 'css/front.css', ['media' => 'all', 'priority' => 200]);
        $controller->registerJavascript('quince-block-front', $path . 'js/front.js', ['position' => 'bottom', 'priority' => 200]);

        Media::addJsDef(['quinceBlock' => [
            'render' => $this->context->link->getModuleLink($this->module->name, 'render', [], true),
            'track' => $this->context->link->getModuleLink($this->module->name, 'track', [], true),
            'refresh' => Configuration::get('QUINCE_BLOCK_REFRESH') !== '0',
            'cmp' => Consent::cmpActive(),
            'consent' => Consent::CODE,
            'utm' => $index['utm'],
            'abCookie' => Consent::AB_COOKIE_PREFIX,
            'utmCookie' => Consent::UTM_COOKIE,
        ]]);
    }

    /** Zamienia krótkie kody `[quince_block id="N"]` na bloki. */
    public function parseShortcodes(string $html): string
    {
        $ids = Shortcodes::ids($html);

        if ($ids === []) {
            return $html;
        }

        $index = $this->reader->index($this->idShop());
        $known = array_values(array_filter($ids, static fn (int $id): bool => isset($index['blocks'][$id])));
        $page = PageContext::fromRequest([], $this->context->controller);
        $rendered = $known === [] ? [] : $this->renderList($known, '', $page);

        return Shortcodes::replace($html, static fn (int $id): string => $rendered[$id] ?? '');
    }

    /**
     * Odświeżenie i leniwe ładowanie: treść bloków bez otoczki.
     *
     * Każda pozycja: `id`, `hook` (pusty dla krótkiego kodu), `ctx` (podpisany
     * kontekst z atrybutu bloku), opcjonalnie `variant` (ten sam wariant co przy
     * pierwszym wyświetleniu). Pozycja z niepoprawnym podpisem albo z blokiem,
     * którego nie ma w tym hooku, zwraca `visible: false`. `key` w wyniku to
     * indeks pozycji w żądaniu.
     *
     * @param array<int,mixed> $items
     *
     * @return array<int,array{key:int,id:int,visible:bool,html:string,variant:string,token:string,random:bool}>
     */
    public function renderItems(array $items): array
    {
        $index = $this->reader->index($this->idShop());
        $out = [];
        $prepared = [];

        foreach (array_slice($items, 0, self::MAX_ITEMS) as $key => $item) {
            if (!is_array($item)) {
                continue;
            }

            $id = (int) ($item['id'] ?? 0);
            $hook = strtolower((string) ($item['hook'] ?? ''));
            $page = PageContext::verify((string) ($item['ctx'] ?? ''));
            $variant = (string) ($item['variant'] ?? '');
            $out[$key] = ['key' => $key, 'id' => $id, 'visible' => false, 'html' => '', 'variant' => 'a', 'token' => '', 'random' => false];

            if ($page === null || !isset($index['blocks'][$id])) {
                continue;
            }

            if ($hook !== '' && (preg_match('/^[a-z0-9_]{1,64}$/', $hook) !== 1 || !in_array($id, $index['hooks'][$hook] ?? [], true))) {
                continue;
            }

            $block = $index['blocks'][$id];

            if (!Schedule::isOpen($block) || !(new RuleEngine(new Facts($page, $this->context)))->passes($block['rules'], $block['rule_logic'])) {
                continue;
            }

            $prepared[$key] = [$block, $page, $variant];
        }

        if ($prepared === []) {
            return array_values($out);
        }

        $contents = $this->reader->contents(
            array_map(static fn (array $p): int => (int) $p[0]['id'], $prepared),
            $this->idShop(),
            $this->idLang()
        );

        foreach ($prepared as $key => [$block, $page, $forced]) {
            $id = (int) $block['id'];
            $content = $contents[$id] ?? null;

            if ($content === null) {
                continue;
            }

            $hasB = trim($content['content_b']) !== '';
            $ab = AbTest::choose($block, $hasB, $this->idShop());

            // Odświeżenie pokazuje ten sam wariant co pierwsze wyświetlenie.
            if (($forced === 'a' || $forced === 'b') && $ab['track']) {
                $ab['variant'] = $forced;
            }

            $html = $ab['variant'] === 'b' ? $content['content_b'] : $content['content'];
            $html = (new Placeholders($this->context))->render($html, $page);

            $out[$key] = [
                'key' => $key,
                'id' => $id,
                'visible' => trim($html) !== '',
                'html' => $html,
                'variant' => $ab['variant'],
                'token' => $ab['track'] ? AbTest::token($id, $this->idShop(), $ab['variant']) : '',
                'random' => $ab['random'],
            ];
        }

        return array_values($out);
    }

    /**
     * Podgląd bloku po prywatnym tokenie: bez względu na aktywność,
     * harmonogram i reguły.
     *
     * @return array{id:int,title:string,html:string,head_code:string,footer_code:string,has_b:bool}|null
     */
    public function preview(string $token, string $variant = 'a', int $idProduct = 0): ?array
    {
        $block = $this->reader->byPreviewToken($token);

        if ($block === null) {
            return null;
        }

        $content = $this->reader->previewContent($block['id_block'], $this->idShop(), $this->idLang());
        $hasB = trim($content['content_b']) !== '';
        $page = PageContext::fromArray(array_merge(
            PageContext::fromRequest([], $this->context->controller)->toArray(),
            ['id_product' => max(0, $idProduct)]
        ));
        $html = ($variant === 'b' && $hasB) ? $content['content_b'] : $content['content'];

        return [
            'id' => $block['id_block'],
            'title' => $block['title'],
            'html' => $this->wrap([
                'id' => $block['id_block'],
                'hook' => '',
                'ctx' => '',
                'refresh' => false,
                'lazy' => false,
                'variant' => ($variant === 'b' && $hasB) ? 'b' : 'a',
                'track' => '',
                'random' => false,
                'hidden' => false,
                'content' => (new Placeholders($this->context))->render($html, $page),
            ]),
            'head_code' => $content['head_code'],
            'footer_code' => $content['footer_code'],
            'has_b' => $hasB,
        ];
    }

    /**
     * Otoczki bloków w kolejności z listy. Klucz: id bloku.
     *
     * @param array<int,int> $ids
     *
     * @return array<int,string>
     */
    private function renderList(array $ids, string $hook, PageContext $page): array
    {
        $index = $this->reader->index($this->idShop());
        $engine = new RuleEngine(new Facts($page, $this->context));
        $plan = [];
        $needContent = [];

        foreach ($ids as $id) {
            $block = $index['blocks'][$id] ?? null;

            if ($block === null || !Schedule::isOpen($block)) {
                continue;
            }

            if ($engine->passes($block['rules'], $block['rule_logic'])) {
                $plan[$id] = 'show';

                if (!$block['lazy_load'] || $block['ab_enabled']) {
                    $needContent[] = $id;
                }
            } elseif ($block['auto_refresh']) {
                // Pusta, ukryta otoczka: po zmianie koszyka blok może zacząć
                // spełniać reguły (np. wartość koszyka) i odświeżenie go pokaże.
                $plan[$id] = 'hidden';
            }
        }

        if ($plan === []) {
            return [];
        }

        $contents = $needContent === [] ? [] : $this->reader->contents($needContent, $this->idShop(), $this->idLang());
        $placeholders = new Placeholders($this->context);
        $out = [];

        foreach ($plan as $id => $mode) {
            $block = $index['blocks'][$id];
            $lazy = $mode === 'show' && $block['lazy_load'];
            $content = $contents[$id] ?? ['content' => '', 'content_b' => ''];

            if ($mode === 'show' && !$lazy && trim($content['content']) === '' && trim($content['content_b']) === '') {
                continue;
            }

            $ab = $mode === 'show'
                ? AbTest::choose($block, trim($content['content_b']) !== '', $this->idShop())
                : ['variant' => 'a', 'random' => false, 'track' => false];

            // Blok leniwy liczy placeholdery dopiero przy doładowaniu.
            $html = '';

            if ($mode === 'show' && !$lazy) {
                $html = $placeholders->render($ab['variant'] === 'b' ? $content['content_b'] : $content['content'], $page);
            }

            $out[$id] = $this->wrap([
                'id' => $id,
                'hook' => $hook,
                'ctx' => ($block['auto_refresh'] || $lazy) ? $page->sign() : '',
                'refresh' => (bool) $block['auto_refresh'],
                'lazy' => $lazy,
                'variant' => $ab['variant'],
                'track' => $ab['track'] ? AbTest::token($id, $this->idShop(), $ab['variant']) : '',
                'random' => $ab['random'],
                'hidden' => $mode === 'hidden',
                'content' => $html,
            ]);
        }

        return $out;
    }

    /**
     * Kod head/stopki z bloków aktywnych w sklepie, które przechodzą
     * harmonogram i reguły na tej stronie. Liczony raz na żądanie.
     *
     * @return array<int,array{head_code:string,footer_code:string}>
     */
    private function codeBlocks(): array
    {
        $memo = $this->idShop() . '-' . $this->idLang();

        if (isset(self::$codeBlocks[$memo])) {
            return self::$codeBlocks[$memo];
        }

        $index = $this->reader->index($this->idShop());
        $candidates = array_filter($index['blocks'], static fn (array $b): bool => !empty($b['code']));

        if ($candidates === []) {
            return self::$codeBlocks[$memo] = [];
        }

        $engine = new RuleEngine(new Facts(PageContext::fromRequest([], $this->context->controller), $this->context));
        $ids = [];

        foreach ($candidates as $id => $block) {
            if (Schedule::isOpen($block) && $engine->passes($block['rules'], $block['rule_logic'])) {
                $ids[] = (int) $id;
            }
        }

        return self::$codeBlocks[$memo] = $ids === [] ? [] : $this->reader->codes($ids, $this->idShop(), $this->idLang());
    }

    /**
     * @param array<string,mixed> $vars
     */
    private function wrap(array $vars): string
    {
        $vars['hook'] = preg_match('/^[a-zA-Z0-9_]{0,64}$/', (string) $vars['hook']) === 1 ? (string) $vars['hook'] : '';
        $vars['variant'] = $vars['variant'] === 'b' ? 'b' : 'a';

        $resource = $this->context->controller instanceof \FrontController
            ? 'module:' . $this->module->name . '/views/templates/front/block.tpl'
            : $this->module->getLocalPath() . 'views/templates/front/block.tpl';

        $smarty = $this->context->smarty;
        $template = $smarty->createTemplate($resource, null, null, $smarty);
        $template->assign('qb', $vars);

        return (string) $template->fetch();
    }

    private function idShop(): int
    {
        return (int) $this->context->shop->id;
    }

    private function idLang(): int
    {
        return (int) $this->context->language->id;
    }
}
