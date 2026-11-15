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
use Quince\Block\Storage\BlockRepository;
use Quince\Block\Storage\RuleRepository;
use Quince\Block\Storage\VersionRepository;
use Quince\Block\Tools\RuleNormalizer;
use Quince\Block\Ui\Choices;
use Quince\Block\Ui\RuleSummary;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Reguły bloku przez JSON: dodanie, edycja, usunięcie i podpowiedzi do pól
 * (produkty, wartości cech). Każda zmiana zapisuje najpierw wersję bloku.
 */
final class RulesController extends BlockBaseController
{
    public function __construct(
        private readonly BlockRepository $blocks,
        private readonly RuleRepository $rules,
        private readonly VersionRepository $versions,
        private readonly RuleNormalizer $normalizer,
        private readonly RuleSummary $summary,
        private readonly Choices $choices,
    ) {
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function saveAction(Request $request): JsonResponse
    {
        if (!$this->csrfValid($request, 'quince_block_rule_save')) {
            return $this->fail($this->t('Invalid security token, please reload the page and try again.'), 403);
        }

        $data = $this->payload($request);
        $idBlock = (int) ($data['id_block'] ?? 0);
        $idRule = (int) ($data['id_rule'] ?? 0);
        $type = (string) ($data['type'] ?? '');

        if ($this->blocks->find($idBlock) === null) {
            return $this->fail($this->t('This block no longer exists.'), 404);
        }

        if ($idRule > 0 && $this->rules->find($idRule, $idBlock) === null) {
            return $this->fail($this->t('This rule no longer exists.'), 404);
        }

        [$value, $error] = $this->normalizer->normalize($type, is_array($data['value'] ?? null) ? $data['value'] : []);

        if ($value === null) {
            return $this->fail($this->t($error), 422);
        }

        $this->versions->add($idBlock, $this->employeeId(), $this->blocks->snapshot($idBlock));
        $this->rules->save($idBlock, $idRule, $type, (string) ($data['mode'] ?? 'include'), $value);
        $this->blocks->writeRow($idBlock, []);
        $this->clearFrontCache();

        return $this->ok([
            'message' => $this->t('Rule saved.'),
            'rules' => $this->rulesView($this->rules, $this->normalizer, $this->summary, $idBlock),
        ]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function deleteAction(Request $request): JsonResponse
    {
        if (!$this->csrfValid($request, 'quince_block_rule_delete')) {
            return $this->fail($this->t('Invalid security token, please reload the page and try again.'), 403);
        }

        $data = $this->payload($request);
        $idBlock = (int) ($data['id_block'] ?? 0);
        $idRule = (int) ($data['id_rule'] ?? 0);

        if ($this->rules->find($idRule, $idBlock) === null) {
            return $this->fail($this->t('This rule no longer exists.'), 404);
        }

        $this->versions->add($idBlock, $this->employeeId(), $this->blocks->snapshot($idBlock));
        $this->rules->delete($idRule, $idBlock);
        $this->blocks->writeRow($idBlock, []);
        $this->clearFrontCache();

        return $this->ok([
            'message' => $this->t('Rule removed.'),
            'rules' => $this->rulesView($this->rules, $this->normalizer, $this->summary, $idBlock),
        ]);
    }

    /** Podpowiedzi do pól reguły i testera: `source=product&q=` albo `source=feature_value&id_feature=`. */
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function lookupAction(Request $request): JsonResponse
    {
        $source = (string) $request->query->get('source', '');
        $idLang = $this->idLang();
        $idShop = $this->currentShopId();

        if ($source === 'product') {
            return $this->ok(['items' => $this->choices->searchProducts((string) $request->query->get('q', ''), $idLang, $idShop)]);
        }

        if ($source === 'feature_value') {
            return $this->ok(['items' => $this->choices->featureValues((int) $request->query->get('id_feature', 0), $idLang)]);
        }

        return $this->fail($this->t('Unknown list.'), 400);
    }
}
