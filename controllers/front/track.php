<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

use Quince\Block\Front\AbTest;
use Quince\Block\Front\Consent;
use Quince\Block\Storage\BlockReader;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Statystyki testu A/B: wyświetlenie albo kliknięcie, tylko POST.
 *
 * Pola: `id`, `variant` (a|b), `kind` (view|click), `token` (podpis bloku
 * z atrybutu `data-qb-token`). Zdarzenie przyjmujemy tylko, gdy blok istnieje
 * w tym sklepie, ma włączony i nierozstrzygnięty test, podpis się zgadza,
 * odwiedzający zgodził się na pomiar (przy `quince_cmp`) i adres nie przekroczył
 * limitu. Limit zatrzymuje pompowanie statystyk, które wybierają zwycięzcę.
 */
class Quince_BlockTrackModuleFrontController extends ModuleFrontController
{
    /** Wszystkie zdarzenia z jednego adresu w oknie. */
    private const LIMIT_ALL = 300;

    /** Wyświetlenia jednego bloku z jednego adresu w oknie. */
    private const LIMIT_VIEWS = 30;

    /** Kliknięcia jednego bloku z jednego adresu w oknie. */
    private const LIMIT_CLICKS = 10;

    /** Okno limitów w sekundach. */
    private const WINDOW = 600;

    public function init(): void
    {
        $this->ajax = true;
        $this->content_only = true;

        parent::init();
    }

    public function initContent(): void
    {
    }

    public function displayAjax(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $this->ajaxRender((string) json_encode(['ok' => $this->accept()]));
    }

    private function accept(): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);

            return false;
        }

        $id = (int) Tools::getValue('id');
        $variant = (string) Tools::getValue('variant');
        $kind = (string) Tools::getValue('kind');
        $token = (string) Tools::getValue('token');
        $idShop = (int) $this->context->shop->id;

        if ($id <= 0 || !in_array($variant, ['a', 'b'], true) || !in_array($kind, ['view', 'click'], true)) {
            return false;
        }

        if (!AbTest::validToken($id, $idShop, $token) || !Consent::trackingAllowed()) {
            return false;
        }

        $block = (new BlockReader())->index($idShop)['blocks'][$id] ?? null;

        if ($block === null || empty($block['ab_enabled'])) {
            return false;
        }

        // Po rozstrzygnięciu wynik ma zostać taki, jaki jest.
        if (!empty($block['ab_auto_optimize']) && isset(AbTest::winners($idShop)[$id])) {
            return false;
        }

        if (!class_exists(\Quince\Engine\Form\FormGuard::class)) {
            return false;
        }

        $guard = new \Quince\Engine\Form\FormGuard();
        $keyAll = 'quince_block_track';
        $keyBlock = 'quince_block_' . $kind . '_' . $id;

        if ($guard->tooOften($keyAll, self::LIMIT_ALL, self::WINDOW)
            || $guard->tooOften($keyBlock, $kind === 'click' ? self::LIMIT_CLICKS : self::LIMIT_VIEWS, self::WINDOW)
        ) {
            return false;
        }

        $guard->accept($keyAll);
        $guard->accept($keyBlock);

        return AbTest::record($id, $idShop, $variant, $kind);
    }
}
