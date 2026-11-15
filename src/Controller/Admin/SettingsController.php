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
use Quince\Engine\Settings\ConfigStore;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Ustawienia modułu per sklep: własny CSS bloków, próg niskiego stanu
 * (placeholder `if_low_stock`) i odświeżanie bloków po zmianie koszyka.
 * Zapis przez ConfigStore silnika: trafia do każdego sklepu z kontekstu.
 */
final class SettingsController extends BlockBaseController
{
    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function indexAction(): Response
    {
        $idShop = \Shop::getContext() === \Shop::CONTEXT_SHOP ? (int) \Shop::getContextShopID() : null;

        $lowStock = \Configuration::get('QUINCE_BLOCK_LOW_STOCK', null, null, $idShop);

        return $this->renderPanel('settings/index.html.twig', 'settings', [
            'customCss' => \Quince\Block\Front\CustomCss::decode((string) \Configuration::get(\Quince\Block\Front\CustomCss::KEY, null, null, $idShop)),
            // Brak wartości albo zero: front liczy z progiem 5.
            'lowStock' => (int) $lowStock > 0 ? (int) $lowStock : 5,
            // Brak wartości znaczy włączone (front sprawdza `!== '0'`).
            'refresh' => \Configuration::get('QUINCE_BLOCK_REFRESH', null, null, $idShop) !== '0',
            'shopNames' => array_column($this->contextShops(), 'name'),
        ]);
    }

    #[AdminSecurity("is_granted('update', request.get('_legacy_controller'))", message: 'You do not have permission to change this.')]
    public function saveAction(Request $request): Response
    {
        if (!$this->csrfValid($request, 'quince_block_settings_save')) {
            return $this->csrfFailed('quince_block_settings');
        }

        // Zamknięcie znacznika `</style>` w treści neutralizuje front przy wypisaniu.
        ConfigStore::set(\Quince\Block\Front\CustomCss::KEY, \Quince\Block\Front\CustomCss::encode((string) $request->request->get('custom_css', '')));
        ConfigStore::set('QUINCE_BLOCK_LOW_STOCK', (string) max(1, (int) $request->request->get('low_stock', 5)));
        ConfigStore::set('QUINCE_BLOCK_REFRESH', !empty($request->request->get('refresh')) ? '1' : '0');

        $this->clearFrontCache();
        $this->addFlash('success', $this->t('Settings saved.'));

        return $this->redirectToRoute('quince_block_settings');
    }
}
