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
use Quince\Block\Storage\HookRepository;
use Quince\Block\Storage\RuleRepository;
use Quince\Block\Tools\BlockTester;
use Quince\Block\Tools\Transfer;
use Quince\Block\Ui\Choices;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Narzędzia: tester bloku, eksport i import JSON.
 */
final class ToolsController extends BlockBaseController
{
    /** Największy plik importu. */
    private const MAX_IMPORT_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private readonly BlockRepository $blocks,
        private readonly HookRepository $hooks,
        private readonly RuleRepository $rules,
        private readonly BlockTester $tester,
        private readonly Transfer $transfer,
        private readonly Choices $choices,
    ) {
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function indexAction(): Response
    {
        return $this->renderPanel('tools/index.html.twig', 'tools', $this->screenData([], null));
    }

    /** Tester niczego nie zapisuje; POST, bo formularz bywa długi i niesie token. */
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function testAction(Request $request): Response
    {
        if (!$this->csrfValid($request, 'quince_block_tools_test')) {
            return $this->csrfFailed('quince_block_tools');
        }

        $input = $request->request->all();
        unset($input['_csrf_token']);

        // Sklep testu tylko z kontekstu pracownika.
        if (!in_array((int) ($input['id_shop'] ?? 0), $this->contextShopIds(), true)) {
            $input['id_shop'] = $this->currentShopId();
        }

        $result = $this->tester->run($input, fn (string $text, array $params = []): string => $this->t($text, $params));

        return $this->renderPanel('tools/index.html.twig', 'tools', $this->screenData($input, $result));
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function exportAction(Request $request): Response
    {
        if (!$this->csrfValid($request, 'quince_block_tools_export')) {
            return $this->csrfFailed('quince_block_tools');
        }

        $json = (string) json_encode($this->transfer->export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return new Response($json, 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="quince-block-' . date('Y-m-d') . '.json"',
            'Cache-Control' => 'no-store',
        ]);
    }

    #[AdminSecurity("is_granted('create', request.get('_legacy_controller'))", message: 'You do not have permission to add this.')]
    public function importAction(Request $request): Response
    {
        if (!$this->csrfValid($request, 'quince_block_tools_import')) {
            return $this->csrfFailed('quince_block_tools');
        }

        $file = $request->files->get('import_file');

        if (!$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile || !$file->isValid()) {
            $this->addFlash('error', $this->t('Choose a JSON file exported from Quince Block.'));

            return $this->redirectToRoute('quince_block_tools');
        }

        if ($file->getSize() > self::MAX_IMPORT_BYTES) {
            $this->addFlash('error', $this->t('The file is larger than 10 MB.'));

            return $this->redirectToRoute('quince_block_tools');
        }

        $data = json_decode((string) file_get_contents($file->getPathname()), true);

        if (!is_array($data)) {
            $this->addFlash('error', $this->t('The file is not valid JSON.'));

            return $this->redirectToRoute('quince_block_tools');
        }

        $replace = !empty($request->request->get('replace'));

        if ($replace && !$this->isGranted('delete', 'AdminQuinceBlock')) {
            $this->addFlash('error', $this->t('You do not have permission to delete this.'));

            return $this->redirectToRoute('quince_block_tools');
        }

        try {
            $result = $this->transfer->import($data, $replace);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $this->t('Nothing was imported. %error%', ['%error%' => $e->getMessage()]));

            return $this->redirectToRoute('quince_block_tools');
        } catch (\Throwable $e) {
            $this->addFlash('error', $this->t('Nothing was imported. %error%', ['%error%' => $e->getMessage()]));

            return $this->redirectToRoute('quince_block_tools');
        }

        foreach ($result['hooks'] as $name) {
            $this->registerModuleHook($name);
        }

        $this->clearFrontCache();
        $this->addFlash('success', $this->t('Imported blocks: %count%.', ['%count%' => $result['blocks']]));

        if ($result['skipped_shops'] !== []) {
            $this->addFlash('warning', $this->t('Shops missing in this installation were skipped: %ids%.', ['%ids%' => implode(', ', $result['skipped_shops'])]));
        }

        return $this->redirectToRoute('quince_block_tools');
    }

    /**
     * @param array<string,mixed> $input ostatnie pola testera
     * @param array<string,mixed>|null $result
     *
     * @return array<string,mixed>
     */
    private function screenData(array $input, ?array $result): array
    {
        $idLang = $this->idLang();
        $idShop = $this->currentShopId();
        $hooks = $this->hooks->all();

        return [
            'counts' => [
                'blocks' => $this->blocks->countAll(),
                'rules' => $this->rules->countAll(),
                'hooks' => count($hooks),
            ],
            'blockChoices' => $this->blocks->choices(),
            'hooks' => $hooks,
            'shops' => $this->contextShops(),
            'languages' => $this->languages(),
            'countries' => $this->choices->list('country', $idLang, $idShop),
            'currencies' => $this->choices->list('currency', $idLang, $idShop),
            'input' => $input + ['id_shop' => $idShop, 'id_lang' => $idLang],
            'result' => $result,
        ];
    }
}
