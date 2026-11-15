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
use Quince\Block\Storage\HookRepository;
use Quince\Block\Storage\Schema;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Hooki, w których moduł wyświetla bloki. Nazwa zawsze zaczyna się od
 * `displayQuinceBlock`, bo tylko takie metody obsługuje `__call()` modułu.
 * Nowy hook dostaje rejestrację modułu, usunięty traci ją razem z przypisaniami bloków.
 */
final class HooksController extends BlockBaseController
{
    public function __construct(private readonly HookRepository $hooks)
    {
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function indexAction(): Response
    {
        return $this->renderPanel('hooks/index.html.twig', 'hooks', [
            'hooks' => $this->hooks->all(),
            'prefix' => Schema::HOOK_PREFIX,
        ]);
    }

    /** Nowy hook (bez `id`) albo zmiana opisu i stanu istniejącego. */
    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function saveAction(Request $request): Response
    {
        if (!$this->csrfValid($request, 'quince_block_hook_save')) {
            return $this->csrfFailed('quince_block_hooks');
        }

        $id = (int) $request->request->get('id', 0);
        $description = trim((string) $request->request->get('description', ''));
        $active = !empty($request->request->get('active'));

        if ($id > 0) {
            $hook = $this->hooks->find($id);

            if ($hook === null) {
                $this->addFlash('error', $this->t('This hook no longer exists.'));

                return $this->redirectToRoute('quince_block_hooks');
            }

            $this->hooks->update($id, $description, $active);
            $this->clearFrontCache();
            $this->addFlash('success', $this->t('Hook saved.'));

            return $this->redirectToRoute('quince_block_hooks');
        }

        $suffix = trim((string) $request->request->get('suffix', ''));

        // Ktoś wkleił pełną nazwę: przedrostek nie może wejść drugi raz.
        if (stripos($suffix, Schema::HOOK_PREFIX) === 0) {
            $suffix = substr($suffix, strlen(Schema::HOOK_PREFIX));
        }

        if (!preg_match(HookRepository::SUFFIX_PATTERN, $suffix)) {
            $this->addFlash('error', $this->t('Use letters, digits and underscores only, up to 40 characters.'));

            return $this->redirectToRoute('quince_block_hooks');
        }

        $name = Schema::HOOK_PREFIX . ucfirst($suffix);

        if ($this->hooks->findByName($name) !== null) {
            $this->addFlash('error', $this->t('A hook with this name already exists.'));

            return $this->redirectToRoute('quince_block_hooks');
        }

        if (!$this->registerModuleHook($name)) {
            $this->addFlash('error', $this->t('The module could not be attached to this hook.'));

            return $this->redirectToRoute('quince_block_hooks');
        }

        $this->hooks->create($name, $description, $active);
        $this->addFlash('success', $this->t('Hook %name% created. Put {hook h=\'%name%\'} in the theme where the blocks should appear.', ['%name%' => $name]));

        return $this->redirectToRoute('quince_block_hooks');
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function toggleAction(Request $request): Response
    {
        if (!$this->csrfValid($request, 'quince_block_hook_toggle')) {
            return $this->csrfFailed('quince_block_hooks');
        }

        $id = (int) $request->request->get('id', 0);

        if ($this->hooks->find($id) !== null) {
            $this->hooks->setActive($id, !empty($request->request->get('active')));
            $this->clearFrontCache();
            $this->addFlash('success', $this->t('Hook saved.'));
        }

        return $this->redirectToRoute('quince_block_hooks');
    }

    #[AdminSecurity("is_granted('delete', request.get('_legacy_controller'))", message: 'You do not have permission to delete this.')]
    public function deleteAction(Request $request): Response
    {
        if (!$this->csrfValid($request, 'quince_block_hook_delete')) {
            return $this->csrfFailed('quince_block_hooks');
        }

        $hook = $this->hooks->find((int) $request->request->get('id', 0));

        if ($hook !== null) {
            $this->hooks->delete($hook['id']);
            $this->unregisterModuleHook($hook['name']);
            $this->clearFrontCache();
            $this->addFlash('success', $this->t('Hook deleted. Blocks assigned to it are no longer shown there.'));
        }

        return $this->redirectToRoute('quince_block_hooks');
    }
}
