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

use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Quince\Block\Render\Placeholders;
use Quince\Block\Storage\BlockRepository;
use Quince\Block\Storage\HookRepository;
use Quince\Block\Storage\RuleRepository;
use Quince\Block\Storage\StatsRepository;
use Quince\Block\Storage\VersionRepository;
use Quince\Block\Tools\RuleNormalizer;
use Quince\Block\Ui\Choices;
use Quince\Block\Ui\RuleSummary;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Edycja bloku jako podstrona listy, sekcje: Treść, Wyświetlanie, Reguły,
 * Test A/B, Historia. Treść i Wyświetlanie (oraz ustawienia testu A/B) to jeden
 * formularz z jednym zapisem; reguły zapisują się osobno przez JSON, bez
 * przeładowania, więc nie gubią niezapisanych zmian formularza.
 */
final class BlockEditController extends BlockBaseController
{
    public const SECTIONS = ['content', 'display', 'rules', 'ab', 'history'];

    public function __construct(
        private readonly BlockRepository $blocks,
        private readonly HookRepository $hooks,
        private readonly RuleRepository $rules,
        private readonly StatsRepository $stats,
        private readonly VersionRepository $versions,
        private readonly RuleNormalizer $normalizer,
        private readonly RuleSummary $summary,
        private readonly Choices $choices,
    ) {
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function editAction(Request $request, int $id): Response
    {
        $row = $id > 0 ? $this->blocks->find($id) : null;

        if ($id > 0 && $row === null) {
            $this->addFlash('error', $this->t('This block no longer exists.'));

            return $this->redirectToRoute('quince_block_blocks');
        }

        $isNew = $row === null;
        $contextShops = $this->contextShops();
        $contextShopIds = $this->contextShopIds();
        $idLang = $this->idLang();
        $idShop = $this->currentShopId();
        $section = (string) $request->query->get('section', 'content');

        $block = $this->formValues($row);
        $selectedShops = $isNew ? $contextShopIds : $this->blocks->shopIds($id);
        $rules = $isNew ? [] : $this->rulesView($this->rules, $this->normalizer, $this->summary, $id);

        // Nazwy produktów z reguł do żetonów w formularzu (produktów nie ładujemy listą).
        $productIds = [];

        foreach ($rules as $rule) {
            foreach ($this->normalizer->definitions()[$rule['type']]['fields'] ?? [] as $field) {
                if (($field['source'] ?? '') === 'product') {
                    $productIds = array_merge($productIds, array_map('intval', (array) ($rule['value'][$field['name']] ?? [])));
                }
            }
        }

        return $this->renderPanel('blocks/edit.html.twig', 'blocks', [
            'layoutTitle' => $isNew ? 'New block' : 'Edit block',
            'id' => $isNew ? 0 : $id,
            'block' => $block,
            'section' => in_array($section, self::SECTIONS, true) ? $section : 'content',
            'languages' => $this->languages(),
            'shops' => $contextShops,
            'selectedShops' => array_values(array_intersect($selectedShops, $contextShopIds)),
            'otherShops' => array_values(array_diff($selectedShops, $contextShopIds)),
            'contents' => $isNew ? [] : $this->blocks->contents($id),
            'hooks' => $this->hooks->all(),
            'selectedHooks' => $isNew ? [] : $this->blocks->hookIds($id),
            'placeholders' => $this->placeholderGroups(),
            'ruleTypes' => $this->uiDefinitions(),
            'rules' => $rules,
            'productNames' => (object) $this->choices->productNames($productIds, $idLang, $idShop),
            'choices' => $this->choices->preloaded($idLang, $idShop),
            'ab' => $isNew ? null : $this->stats->abResults($id, $contextShopIds),
            'versions' => $isNew ? [] : $this->versions->forBlock($id),
            'previewUrl' => $isNew || $block['preview_token'] === '' ? '' : $this->previewUrl($block['preview_token'], $selectedShops, $contextShopIds),
        ]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function saveAction(Request $request): Response
    {
        $id = (int) $request->request->get('id', 0);
        $section = $this->section($request);

        if (!$this->csrfValid($request, 'quince_block_block_save')) {
            return $this->csrfFailed($id > 0 ? 'quince_block_block_edit' : 'quince_block_blocks', $id > 0 ? ['id' => $id] : []);
        }

        if ($id > 0 && $this->blocks->find($id) === null) {
            $this->addFlash('error', $this->t('This block no longer exists.'));

            return $this->redirectToRoute('quince_block_blocks');
        }

        if ($id === 0 && !$this->isGranted('create', 'AdminQuinceBlock')) {
            $this->addFlash('error', $this->t('You do not have permission to add this.'));

            return $this->redirectToRoute('quince_block_blocks');
        }

        $post = $request->request;
        $contextShopIds = $this->contextShopIds();
        [$row, $warnings] = $this->rowFromRequest($post->all());

        $contents = $this->contentsFromRequest($post->all('content'), $contextShopIds);

        if ($this->isMultistore()) {
            $shopIds = array_values(array_intersect(array_map('intval', $post->all('shops')), $contextShopIds));
        } else {
            $shopIds = $contextShopIds;
        }

        $hookIds = array_values(array_intersect(array_map('intval', $post->all('hooks')), $this->hooks->ids()));

        // Historia: stan sprzed zmiany.
        if ($id > 0) {
            $this->versions->add($id, $this->employeeId(), $this->blocks->snapshot($id));
        }

        try {
            $id = $this->blocks->save($id, $row, $contents, $shopIds, $contextShopIds, $hookIds);
        } catch (\Throwable $e) {
            $this->addFlash('error', $this->t('The block could not be saved: %error%', ['%error%' => $e->getMessage()]));

            return $id > 0 ? $this->redirectToRoute('quince_block_block_edit', ['id' => $id, 'section' => $section]) : $this->redirectToRoute('quince_block_blocks');
        }

        $this->clearFrontCache();

        foreach ($warnings as $warning) {
            $this->addFlash('warning', $warning);
        }

        if ($hookIds === []) {
            $this->addFlash('warning', $this->t('The block has no hook yet, so it is not shown anywhere.'));
        }

        $this->addFlash('success', $this->t('Block saved.'));

        return $this->redirectToRoute('quince_block_block_edit', ['id' => $id, 'section' => $section]);
    }

    /** Nowy prywatny link podglądu (stary przestaje działać). */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function previewAction(Request $request): Response
    {
        $id = (int) $request->request->get('id', 0);

        if (!$this->csrfValid($request, 'quince_block_block_preview')) {
            return $this->csrfFailed('quince_block_block_edit', ['id' => $id]);
        }

        if ($this->blocks->find($id) === null) {
            return $this->redirectToRoute('quince_block_blocks');
        }

        $this->blocks->setPreviewToken($id, bin2hex(random_bytes(24)));
        $this->addFlash('success', $this->t('A new preview link is ready. The previous link no longer works.'));

        return $this->redirectToRoute('quince_block_block_edit', ['id' => $id, 'section' => $this->section($request)]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function abResetAction(Request $request): Response
    {
        $id = (int) $request->request->get('id', 0);

        if (!$this->csrfValid($request, 'quince_block_block_ab_reset')) {
            return $this->csrfFailed('quince_block_block_edit', ['id' => $id, 'section' => 'ab']);
        }

        if ($this->blocks->find($id) === null) {
            return $this->redirectToRoute('quince_block_blocks');
        }

        $this->stats->reset($id, $this->contextShopIds());
        $this->clearFrontCache();
        $this->addFlash('success', $this->t('The test results were cleared. Counting starts again.'));

        return $this->redirectToRoute('quince_block_block_edit', ['id' => $id, 'section' => 'ab']);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function restoreAction(Request $request): Response
    {
        $id = (int) $request->request->get('id', 0);

        if (!$this->csrfValid($request, 'quince_block_block_restore')) {
            return $this->csrfFailed('quince_block_block_edit', ['id' => $id, 'section' => 'history']);
        }

        $snapshot = $this->versions->snapshot((int) $request->request->get('id_version', 0), $id);

        if ($this->blocks->find($id) === null || $snapshot === null) {
            $this->addFlash('error', $this->t('This version no longer exists.'));

            return $this->redirectToRoute('quince_block_block_edit', ['id' => $id, 'section' => 'history']);
        }

        // Bieżący stan też trafia do historii: przywrócenie da się cofnąć.
        $this->versions->add($id, $this->employeeId(), $this->blocks->snapshot($id));

        try {
            $this->blocks->restore($id, $snapshot, $this->contextShopIds(), $this->hooks->ids());
        } catch (\Throwable $e) {
            $this->addFlash('error', $this->t('The version could not be restored: %error%', ['%error%' => $e->getMessage()]));

            return $this->redirectToRoute('quince_block_block_edit', ['id' => $id, 'section' => 'history']);
        }

        $this->clearFrontCache();
        $this->addFlash('success', $this->t('Version restored.'));

        return $this->redirectToRoute('quince_block_block_edit', ['id' => $id, 'section' => 'history']);
    }

    /**
     * Pola wiersza z formularza, sprawdzone i sprowadzone do zapisu.
     *
     * @param array<string,mixed> $post
     *
     * @return array{0:array<string,mixed>,1:string[]}
     */
    private function rowFromRequest(array $post): array
    {
        $warnings = [];
        $dateFrom = $this->date((string) ($post['date_from'] ?? ''));
        $dateTo = $this->date((string) ($post['date_to'] ?? ''));

        if ($dateFrom !== null && $dateTo !== null && $dateTo < $dateFrom) {
            $warnings[] = $this->t('The end date is before the start date, so the block will not show.');
        }

        $days = array_values(array_unique(array_filter(array_map('intval', (array) ($post['days'] ?? [])), static fn (int $d): bool => $d >= 1 && $d <= 7)));
        sort($days);

        // Wszystkie dni albo żaden znaczą to samo: każdy dzień.
        $daysCsv = count($days) === 7 ? '' : implode(',', $days);

        return [[
            'title' => mb_substr(trim((string) ($post['title'] ?? '')), 0, 255),
            'active' => !empty($post['active']) ? 1 : 0,
            'position' => max(0, (int) ($post['position'] ?? 0)),
            'rule_logic' => ($post['rule_logic'] ?? '') === 'or' ? 'or' : 'and',
            'date_from' => $dateFrom !== null ? $dateFrom . ' 00:00:00' : null,
            'date_to' => $dateTo !== null ? $dateTo . ' 23:59:59' : null,
            'days' => $daysCsv,
            'time_from' => $this->time((string) ($post['time_from'] ?? '')),
            'time_to' => $this->time((string) ($post['time_to'] ?? '')),
            'auto_refresh' => !empty($post['auto_refresh']) ? 1 : 0,
            'lazy_load' => !empty($post['lazy_load']) ? 1 : 0,
            'ab_enabled' => !empty($post['ab_enabled']) ? 1 : 0,
            'ab_auto_optimize' => !empty($post['ab_auto_optimize']) ? 1 : 0,
            'ab_min_views' => max(100, (int) ($post['ab_min_views'] ?? 100)),
        ], $warnings];
    }

    /**
     * Treści z formularza: tylko sklepy z kontekstu i istniejące języki.
     *
     * @param array<mixed> $posted
     * @param int[] $contextShopIds
     *
     * @return array<int,array<int,array<string,string>>>
     */
    private function contentsFromRequest(array $posted, array $contextShopIds): array
    {
        $langIds = array_column($this->languages(), 'id_lang');
        $out = [];

        foreach ($posted as $idShop => $langs) {
            if (!in_array((int) $idShop, $contextShopIds, true) || !is_array($langs)) {
                continue;
            }

            foreach ($langs as $idLang => $fields) {
                if (!in_array((int) $idLang, $langIds, true) || !is_array($fields)) {
                    continue;
                }

                foreach (BlockRepository::CONTENT_FIELDS as $field) {
                    $out[(int) $idShop][(int) $idLang][$field] = (string) ($fields[$field] ?? '');
                }
            }
        }

        return $out;
    }

    /**
     * Wartości formularza: z bazy albo domyślne dla nowego bloku.
     *
     * @param array<string,mixed>|null $row
     *
     * @return array<string,mixed>
     */
    private function formValues(?array $row): array
    {
        $row ??= [];
        $days = array_values(array_filter(array_map('intval', explode(',', (string) ($row['days'] ?? '')))));

        return [
            'title' => (string) ($row['title'] ?? ''),
            'active' => $row === [] ? true : (bool) $row['active'],
            'position' => (int) ($row['position'] ?? 0),
            'rule_logic' => (string) ($row['rule_logic'] ?? 'and') === 'or' ? 'or' : 'and',
            'date_from' => !empty($row['date_from']) ? substr((string) $row['date_from'], 0, 10) : '',
            'date_to' => !empty($row['date_to']) ? substr((string) $row['date_to'], 0, 10) : '',
            // Pusty zapis = każdy dzień, w formularzu wszystkie zaznaczone.
            'days' => $days === [] ? [1, 2, 3, 4, 5, 6, 7] : $days,
            'time_from' => !empty($row['time_from']) ? substr((string) $row['time_from'], 0, 5) : '',
            'time_to' => !empty($row['time_to']) ? substr((string) $row['time_to'], 0, 5) : '',
            'auto_refresh' => (bool) ($row['auto_refresh'] ?? false),
            'lazy_load' => (bool) ($row['lazy_load'] ?? false),
            'ab_enabled' => (bool) ($row['ab_enabled'] ?? false),
            'ab_auto_optimize' => (bool) ($row['ab_auto_optimize'] ?? false),
            'ab_min_views' => (int) ($row['ab_min_views'] ?? 100),
            'preview_token' => (string) ($row['preview_token'] ?? ''),
            'date_upd' => (string) ($row['date_upd'] ?? ''),
        ];
    }

    /**
     * Definicje typów reguł dla formularza: etykiety przetłumaczone, opcje w jednym kształcie.
     *
     * @return array<int,array<string,mixed>>
     */
    private function uiDefinitions(): array
    {
        $out = [];

        foreach ($this->normalizer->definitions() as $type => $definition) {
            $fields = [];

            foreach ((array) ($definition['fields'] ?? []) as $field) {
                if (!is_array($field) || !isset($field['name'])) {
                    continue;
                }

                $fields[] = [
                    'name' => (string) $field['name'],
                    'kind' => (string) ($field['kind'] ?? 'text'),
                    'source' => (string) ($field['source'] ?? ''),
                    'multiple' => RuleNormalizer::isMultiple($field),
                    // Etykiety z definicji są już przetłumaczone przez część frontu.
                    'label' => !empty($field['label']) ? (string) $field['label'] : $this->t(RuleSummary::fieldLabel($field)),
                    'required' => !empty($field['required']),
                    'options' => RuleNormalizer::options($field),
                ];
            }

            $out[] = [
                'type' => (string) $type,
                'label' => (string) ($definition['label'] ?? $type),
                'fields' => $fields,
            ];
        }

        return $out;
    }

    /** @return array<string,array<int,array{token:string,label:string}>> placeholdery w grupach */
    private function placeholderGroups(): array
    {
        if (!class_exists(Placeholders::class)) {
            return [];
        }

        $out = [];

        foreach ((array) Placeholders::catalogue() as $item) {
            if (!is_array($item) || empty($item['token'])) {
                continue;
            }

            $group = (string) ($item['group'] ?? 'Other');
            $out[$group][] = ['token' => (string) $item['token'], 'label' => (string) ($item['label'] ?? '')];
        }

        return $out;
    }

    /**
     * Link podglądu w sklepie, w którym blok się pokazuje (pierwszy z kontekstu).
     *
     * @param int[] $selectedShops
     * @param int[] $contextShopIds
     */
    private function previewUrl(string $token, array $selectedShops, array $contextShopIds): string
    {
        $idShop = array_values(array_intersect($selectedShops, $contextShopIds))[0] ?? $this->currentShopId();

        return \Context::getContext()->link->getModuleLink('quince_block', 'preview', ['token' => $token], true, $this->idLang(), $idShop);
    }

    private function section(Request $request): string
    {
        $section = (string) $request->request->get('section', 'content');

        return in_array($section, self::SECTIONS, true) ? $section : 'content';
    }

    private function date(string $value): ?string
    {
        $value = trim($value);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        [$y, $m, $d] = array_map('intval', explode('-', $value));

        return checkdate($m, $d, $y) ? $value : null;
    }

    private function time(string $value): ?string
    {
        $value = trim($value);

        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value)) {
            return null;
        }

        return $value . ':00';
    }
}
