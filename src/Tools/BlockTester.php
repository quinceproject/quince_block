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

use Context;
use DateTimeImmutable;
use Db;
use Quince\Block\Front\PageContext;
use Quince\Block\Rule\Facts;
use Quince\Block\Rule\RuleEngine;
use Quince\Block\Rule\Schedule;
use Quince\Block\Storage\BlockRepository;
use Quince\Block\Storage\HookRepository;
use Quince\Block\Storage\RuleRepository;
use Quince\Block\Storage\Schema;
use Quince\Block\Ui\RuleSummary;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Tester bloku: czy blok pokaże się na wskazanej stronie i dlaczego.
 *
 * Reguły i harmonogram ocenia kod frontu (`RuleEngine`, `Facts`, `Schedule`),
 * więc tester liczy dokładnie to samo co sklep. Strona (produkt, kategoria,
 * rodzaj strony, adres, fraza) i część odwiedzającego (sklep, język, waluta,
 * kraj) pochodzą z formularza: front dostaje kopię kontekstu z tymi wartościami.
 *
 * Reguł opartych na zalogowanym kliencie i jego koszyku (grupa, stan konta,
 * wartość i produkty koszyka) oraz na urządzeniu nie da się odtworzyć w panelu
 * bez prawdziwego klienta i koszyka; mają wynik „nie wiadomo”.
 */
final class BlockTester
{
    public const MATCH = 'match';
    public const NO_MATCH = 'no_match';
    public const UNKNOWN = 'unknown';

    /** Typy zależne od klienta, koszyka i urządzenia odwiedzającego. */
    private const VISITOR_TYPES = ['customer_group', 'customer', 'cart_value', 'cart_product', 'device'];

    /** @var callable(string,array<string,mixed>=):string */
    private $t;

    public function __construct(
        private readonly BlockRepository $blocks,
        private readonly HookRepository $hooks,
        private readonly RuleRepository $rules,
        private readonly RuleNormalizer $normalizer,
        private readonly RuleSummary $summary,
    ) {
    }

    /**
     * @param array<string,mixed> $input pola formularza testera
     * @param callable(string,array<string,mixed>=):string $t tłumaczenie
     *
     * @return array<string,mixed>
     */
    public function run(array $input, callable $t): array
    {
        $this->t = $t;

        $idShop = (int) ($input['id_shop'] ?? 0) ?: (int) \Configuration::get('PS_SHOP_DEFAULT');
        $idLang = (int) ($input['id_lang'] ?? 0) ?: (int) \Configuration::get('PS_LANG_DEFAULT');
        $page = $this->page($input);
        $now = $this->now((string) ($input['datetime'] ?? ''));

        // Fakty frontu liczone są raz na żądanie; tester buduje własne.
        Facts::reset();
        $facts = new Facts($page, $this->context($input, $idShop, $idLang));
        $engine = new RuleEngine($facts);

        $idBlock = (int) ($input['id_block'] ?? 0);
        $idHook = (int) ($input['id_hook'] ?? 0);
        $hook = $idHook > 0 ? $this->hooks->find($idHook) : null;

        if ($idBlock > 0) {
            $ids = [$idBlock];
        } elseif ($hook !== null) {
            $ids = array_map('intval', array_column(Db::getInstance()->executeS(
                'SELECT id_block FROM `' . _DB_PREFIX_ . Schema::BLOCK_HOOK . '` WHERE id_hook = ' . $idHook
            ) ?: [], 'id_block'));
        } else {
            $ids = array_column($this->blocks->choices(), 'id');
        }

        $results = [];

        foreach ($ids as $id) {
            $row = $this->blocks->find((int) $id);

            if ($row !== null) {
                $results[] = $this->testBlock($row, $hook, $engine, $now, $idShop, $idLang);
            }
        }

        $view = $this->factsView($facts, $now);
        Facts::reset();

        return ['facts' => $view, 'blocks' => $results];
    }

    /**
     * @param array<string,mixed> $row
     * @param array{id:int,name:string,active:bool}|null $hook
     *
     * @return array<string,mixed>
     */
    private function testBlock(array $row, ?array $hook, RuleEngine $engine, DateTimeImmutable $now, int $idShop, int $idLang): array
    {
        $id = (int) $row['id_block'];
        $checks = [
            $this->check('Block switched on', (bool) $row['active']),
            $this->check('Shown in this shop', in_array($idShop, $this->blocks->shopIds($id), true)),
        ];

        if ($hook !== null) {
            $checks[] = $this->check('Assigned to the hook', in_array($hook['id'], $this->blocks->hookIds($id), true));
            $checks[] = $this->check('Hook switched on', $hook['active']);
        }

        $schedule = $row;
        $schedule['days'] = array_values(array_filter(array_map('intval', explode(',', (string) $row['days']))));
        $checks[] = $this->check('Within the schedule', Schedule::isOpen($schedule, $now));

        $logic = (string) $row['rule_logic'] === 'or' ? 'or' : 'and';
        $rules = $this->rules->forBlock($id);
        $known = array_values(array_filter($rules, static fn (array $r): bool => !in_array($r['type'], self::VISITOR_TYPES, true)));
        $explained = $engine->explain($known, $logic);
        $matched = array_column($explained['rules'], 'matched');
        $definitions = $this->normalizer->definitions();
        $ruleResults = [];
        $k = 0;

        foreach ($rules as $rule) {
            $unknown = in_array($rule['type'], self::VISITOR_TYPES, true);
            $result = $unknown ? self::UNKNOWN : (!empty($matched[$k++]) ? self::MATCH : self::NO_MATCH);
            $definition = $definitions[$rule['type']] ?? null;

            $ruleResults[] = [
                'label' => $definition ? (string) ($definition['label'] ?? $rule['type']) : $rule['type'],
                'mode' => $rule['mode'],
                'summary' => $definition
                    ? $this->summary->describe($definition, $rule['value'], $idLang, $idShop, fn (string $s): string => ($this->t)($s))
                    : '',
                'result' => $result,
                'note' => $unknown ? ($this->t)('Depends on the signed in customer, the cart or the device, so the tester cannot check it.') : '',
            ];
        }

        $rulesVerdict = count($known) === count($rules)
            ? ($explained['passes'] ? self::MATCH : self::NO_MATCH)
            : $this->verdictWithUnknown($ruleResults, $logic);

        $checksOk = !in_array(false, array_column($checks, 'ok'), true);

        return [
            'id' => $id,
            'title' => (string) $row['title'],
            'logic' => $logic,
            'checks' => $checks,
            'rules' => $ruleResults,
            'verdict' => !$checksOk || $rulesVerdict === self::NO_MATCH ? self::NO_MATCH : $rulesVerdict,
        ];
    }

    /**
     * Wynik, gdy część reguł jest nieznana: pokazuje się na pewno, nie na pewno
     * albo zależnie od odwiedzającego. Wyniki pojedynczych reguł są z frontu.
     *
     * @param array<int,array{mode:string,result:string}> $results
     */
    private function verdictWithUnknown(array $results, string $logic): string
    {
        $include = [];

        foreach ($results as $r) {
            if ($r['mode'] === 'exclude') {
                if ($r['result'] === self::MATCH) {
                    return self::NO_MATCH;
                }
            } else {
                $include[] = $r['result'];
            }
        }

        $excludeUnknown = in_array(self::UNKNOWN, array_column(array_filter($results, static fn (array $r): bool => $r['mode'] === 'exclude'), 'result'), true);

        if ($include === []) {
            return $excludeUnknown ? self::UNKNOWN : self::MATCH;
        }

        if ($logic === 'and') {
            $verdict = in_array(self::NO_MATCH, $include, true) ? self::NO_MATCH
                : (in_array(self::UNKNOWN, $include, true) ? self::UNKNOWN : self::MATCH);
        } else {
            $verdict = in_array(self::MATCH, $include, true) ? self::MATCH
                : (in_array(self::UNKNOWN, $include, true) ? self::UNKNOWN : self::NO_MATCH);
        }

        return $verdict === self::MATCH && $excludeUnknown ? self::UNKNOWN : $verdict;
    }

    /** @param array<string,mixed> $in */
    private function page(array $in): PageContext
    {
        $idProduct = max(0, (int) ($in['id_product'] ?? 0));
        $idCategory = max(0, (int) ($in['id_category'] ?? 0));
        $page = (string) ($in['page'] ?? '');

        if ($page === '') {
            $page = match (true) {
                $idProduct > 0 => 'product',
                $idCategory > 0 => 'category',
                (int) ($in['id_manufacturer'] ?? 0) > 0 => 'manufacturer',
                (int) ($in['id_supplier'] ?? 0) > 0 => 'supplier',
                default => 'index',
            };
        }

        // Parametr UTM wchodzi w adres strony: front czyta go stamtąd.
        $url = trim((string) ($in['url'] ?? '')) ?: '/';
        $param = (string) ($in['utm_param'] ?? '');
        $value = trim((string) ($in['utm_value'] ?? ''));

        if ($value !== '' && in_array($param, ['source', 'medium', 'campaign', 'term', 'content'], true)) {
            $url .= (str_contains($url, '?') ? '&' : '?') . 'utm_' . $param . '=' . rawurlencode($value);
        }

        return PageContext::fromArray([
            'id_product' => $idProduct,
            'id_category' => $idCategory,
            // Strona marki i strona dostawcy: bez tych numerów reguły marki
            // i dostawcy dawały w testerze odwrotny wynik niż w sklepie.
            'id_manufacturer' => max(0, (int) ($in['id_manufacturer'] ?? 0)),
            'id_supplier' => max(0, (int) ($in['id_supplier'] ?? 0)),
            'page' => $page,
            'search' => trim((string) ($in['search'] ?? '')),
            'url' => $url,
        ]);
    }

    /**
     * Kopia kontekstu z wartościami testu, bez klienta i koszyka pracownika.
     *
     * @param array<string,mixed> $in
     */
    private function context(array $in, int $idShop, int $idLang): Context
    {
        $context = clone Context::getContext();
        $context->shop = new \Shop($idShop);
        $context->language = new \Language($idLang);

        $idCurrency = (int) ($in['id_currency'] ?? 0) ?: (int) \Configuration::get('PS_CURRENCY_DEFAULT', null, null, $idShop);
        $context->currency = new \Currency($idCurrency);

        $idCountry = (int) ($in['id_country'] ?? 0) ?: (int) \Configuration::get('PS_COUNTRY_DEFAULT', null, null, $idShop);
        $context->country = new \Country($idCountry);

        $context->customer = new \Customer();
        $context->cart = null;

        return $context;
    }

    /** Chwila testu w strefie sklepu; puste pole = teraz. */
    private function now(string $value): DateTimeImmutable
    {
        $now = Schedule::now();

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value)) {
            $parsed = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value, $now->getTimezone());

            return $parsed ?: $now;
        }

        return $now;
    }

    /** @return array{label:string,ok:bool} */
    private function check(string $label, bool $ok): array
    {
        return ['label' => ($this->t)($label), 'ok' => $ok];
    }

    /** @return array<int,array{label:string,value:string}> co front przyjął o stronie */
    private function factsView(Facts $facts, DateTimeImmutable $now): array
    {
        $t = $this->t;
        $categories = $facts->hasProduct() ? $facts->productCategories()['direct'] : $facts->listingCategoryTree();

        return array_values(array_filter([
            ['label' => $t('Date and time'), 'value' => $now->format('Y-m-d H:i')],
            ['label' => $t('Page type'), 'value' => $facts->page->page],
            ['label' => $t('Categories'), 'value' => implode(', ', (array) $categories)],
            ['label' => $t('Brand'), 'value' => $facts->manufacturerId() > 0 ? (string) $facts->manufacturerId() : ''],
            ['label' => $t('Supplier'), 'value' => $facts->supplierId() > 0 ? (string) $facts->supplierId() : ''],
            ['label' => $t('Tags'), 'value' => implode(', ', $facts->tags())],
        ], static fn (array $r): bool => $r['value'] !== ''));
    }
}
