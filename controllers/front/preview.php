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
 * Podgląd bloku w sklepie przed włączeniem, po prywatnym linku
 * `index.php?fc=module&module=quince_block&controller=preview&token=...`.
 *
 * Pokazuje treść bez względu na aktywność, harmonogram i reguły. Opcjonalnie
 * `variant=b` (wariant B testu) i `id_product` (produkt do placeholderów).
 * Zły albo pusty token kończy się stroną 404, żeby link nie zdradzał,
 * czy taki blok istnieje.
 */
class Quince_BlockPreviewModuleFrontController extends ModuleFrontController
{
    /** @var array<string,mixed>|null */
    private ?array $preview = null;

    public function init(): void
    {
        parent::init();

        $token = (string) Tools::getValue('token');
        $variant = Tools::getValue('variant') === 'b' ? 'b' : 'a';
        $this->preview = (new FrontRenderer($this->module))->preview($token, $variant, (int) Tools::getValue('id_product'));

        if ($this->preview === null) {
            header('HTTP/1.1 404 Not Found');
            header('Status: 404 Not Found');
            $this->errors[] = $this->trans('This preview link is not valid.', [], 'Modules.Quinceblock.Shop');
        }

        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store, private');
    }

    public function initContent(): void
    {
        parent::initContent();

        if ($this->preview === null) {
            $this->setTemplate('errors/404');

            return;
        }

        $token = (string) Tools::getValue('token');
        $idProduct = (int) Tools::getValue('id_product');
        $params = ['token' => $token] + ($idProduct > 0 ? ['id_product' => $idProduct] : []);

        $this->context->smarty->assign('qb_preview', [
            // Tytuł idzie przez {l sprintf}, który nie escapuje wyniku.
            'title' => htmlspecialchars((string) $this->preview['title'], ENT_QUOTES, 'UTF-8'),
            'html' => $this->preview['html'],
            'head_code' => $this->preview['head_code'],
            'footer_code' => $this->preview['footer_code'],
            'has_b' => $this->preview['has_b'],
            'url_a' => $this->context->link->getModuleLink($this->module->name, 'preview', $params + ['variant' => 'a'], true),
            'url_b' => $this->context->link->getModuleLink($this->module->name, 'preview', $params + ['variant' => 'b'], true),
        ]);

        $this->setTemplate('module:' . $this->module->name . '/views/templates/front/preview.tpl');
    }

    /** @return array<string,mixed> */
    public function getTemplateVarPage(): array
    {
        $page = parent::getTemplateVarPage();
        $page['meta']['robots'] = 'noindex, nofollow';
        $page['meta']['title'] = $this->trans('Block preview', [], 'Modules.Quinceblock.Shop');

        return $page;
    }
}
