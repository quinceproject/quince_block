<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

use Quince\Block\Front\FrontRenderer;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Odświeżenie bloków po zmianie koszyka i leniwe ładowanie.
 *
 * Jedno żądanie POST na wszystkie bloki naraz. W polu `items` lista JSON:
 * `[{"id":12,"hook":"displayQuinceBlockCart","ctx":"...","variant":"a"}]`.
 * Kontekst strony przychodzi podpisany z atrybutu bloku, więc klient nie
 * zmieni produktu ani kategorii, żeby obejść reguły. Odpowiedź:
 * `{"blocks":[{"id":12,"visible":true,"html":"...","variant":"a","token":"","random":false}]}`.
 */
class Quince_BlockRenderModuleFrontController extends ModuleFrontController
{
    public function init(): void
    {
        // Rdzeń ustawia `ajax` w konstruktorze z nagłówka Accept, więc
        // wymuszamy go tutaj. `content_only` pomija nagłówek i stopkę strony.
        $this->ajax = true;
        $this->content_only = true;

        parent::init();
    }

    /** Bez zmiennych szablonu strony: odpowiedź to sam JSON. */
    public function initContent(): void
    {
    }

    public function displayAjax(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            $this->ajaxRender((string) json_encode(['blocks' => []]));

            return;
        }

        // Surowe pole, nie Tools::getValue(): to zdejmuje ukośniki z JSON-a.
        $raw = isset($_POST['items']) && is_string($_POST['items']) ? $_POST['items'] : '';
        $items = strlen($raw) <= 200000 ? json_decode($raw, true) : null;

        $blocks = is_array($items) ? (new FrontRenderer($this->module))->renderItems($items) : [];

        $this->ajaxRender((string) json_encode(
            ['blocks' => $blocks],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
    }
}
