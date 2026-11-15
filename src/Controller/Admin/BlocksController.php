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
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Lista bloków: wyszukiwanie, filtr statusu, stronicowanie, przełącznik
 * aktywności, akcje zbiorcze, duplikowanie i usuwanie.
 */
final class BlocksController extends BlockBaseController
{
    private const PER_PAGE = 20;

    public function __construct(private readonly BlockRepository $blocks)
    {
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function indexAction(Request $request): Response
    {
        $query = trim((string) $request->query->get('q', ''));
        $status = (string) $request->query->get('status', '');
        $status = in_array($status, ['active', 'inactive'], true) ? $status : '';

        $total = $this->blocks->count($query, $status);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int) $request->query->get('page', 1)));
        $rows = $this->blocks->search($query, $status, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        $ids = array_map(static fn (array $r): int => (int) $r['id_block'], $rows);
        $hooks = $this->blocks->hookNamesFor($ids);
        $shops = $this->blocks->shopIdsFor($ids);
        $list = [];

        foreach ($rows as $row) {
            $id = (int) $row['id_block'];
            $list[] = [
                'id' => $id,
                'title' => (string) $row['title'],
                'active' => (bool) $row['active'],
                'position' => (int) $row['position'],
                'logic' => (string) $row['rule_logic'],
                'date_from' => $row['date_from'] ? substr((string) $row['date_from'], 0, 10) : '',
                'date_to' => $row['date_to'] ? substr((string) $row['date_to'], 0, 10) : '',
                'ab' => (bool) $row['ab_enabled'],
                'hooks' => $hooks[$id] ?? [],
                'shops' => array_map(fn (int $s): string => $this->shopName($s), $shops[$id] ?? []),
            ];
        }

        return $this->renderPanel('blocks/index.html.twig', 'blocks', [
            'rows' => $list,
            'filters' => array_filter(['q' => $query, 'status' => $status]),
            'query' => $query,
            'status' => $status,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'anyBlock' => $this->blocks->countAll() > 0,
        ]);
    }

    /** Przełącznik aktywności z listy (JSON). */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function toggleAction(Request $request): JsonResponse
    {
        if (!$this->csrfValid($request, 'quince_block_block_toggle')) {
            return $this->fail($this->t('Invalid security token, please reload the page and try again.'), 403);
        }

        $data = $this->payload($request);
        $id = (int) ($data['id'] ?? 0);

        if ($this->blocks->find($id) === null) {
            return $this->fail($this->t('This block no longer exists.'), 404);
        }

        $this->blocks->setActive([$id], !empty($data['active']));
        $this->clearFrontCache();

        return $this->ok(['active' => !empty($data['active'])]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function bulkAction(Request $request): Response
    {
        if (!$this->csrfValid($request, 'quince_block_block_bulk')) {
            return $this->csrfFailed('quince_block_blocks');
        }

        $ids = array_values(array_filter(array_map('intval', (array) $request->request->all('ids')), static fn (int $i): bool => $i > 0));
        $action = (string) $request->request->get('bulk_action', '');

        if ($ids === []) {
            $this->addFlash('warning', $this->t('Select at least one block.'));

            return $this->redirectToRoute('quince_block_blocks');
        }

        if ($action === 'enable' || $action === 'disable') {
            $this->blocks->setActive($ids, $action === 'enable');
            $this->addFlash('success', $this->t('Blocks updated.'));
        } elseif ($action === 'delete') {
            if (!$this->isGranted('delete', 'AdminQuinceBlock')) {
                $this->addFlash('error', $this->t('You do not have permission to delete this.'));

                return $this->redirectToRoute('quince_block_blocks');
            }

            $this->blocks->delete($ids);
            $this->addFlash('success', $this->t('Blocks deleted.'));
        } else {
            return $this->redirectToRoute('quince_block_blocks');
        }

        $this->clearFrontCache();

        return $this->redirectToRoute('quince_block_blocks');
    }

    #[AdminSecurity("is_granted('create', request.get('_legacy_controller'))", message: 'You do not have permission to add this.')]
    public function duplicateAction(Request $request): Response
    {
        if (!$this->csrfValid($request, 'quince_block_block_duplicate')) {
            return $this->csrfFailed('quince_block_blocks');
        }

        $newId = $this->blocks->duplicate((int) $request->request->get('id', 0), ' ' . $this->t('(copy)'));

        if ($newId <= 0) {
            $this->addFlash('error', $this->t('This block no longer exists.'));

            return $this->redirectToRoute('quince_block_blocks');
        }

        $this->addFlash('success', $this->t('Block duplicated. The copy is switched off until you turn it on.'));

        return $this->redirectToRoute('quince_block_block_edit', ['id' => $newId]);
    }

    #[AdminSecurity("is_granted('delete', request.get('_legacy_controller'))", message: 'You do not have permission to delete this.')]
    public function deleteAction(Request $request): Response
    {
        if (!$this->csrfValid($request, 'quince_block_block_delete')) {
            return $this->csrfFailed('quince_block_blocks');
        }

        $id = (int) $request->request->get('id', 0);

        if ($this->blocks->find($id) !== null) {
            $this->blocks->delete([$id]);
            $this->clearFrontCache();
            $this->addFlash('success', $this->t('Block deleted.'));
        }

        return $this->redirectToRoute('quince_block_blocks');
    }
}
