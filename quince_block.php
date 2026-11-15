<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use Quince\Block\Front\FrontRenderer;
use Quince\Block\Storage\Schema;

/**
 * Quince Block: bloki treści z regułami wyświetlania, harmonogramem i testem A/B.
 *
 * Blok to HTML (z placeholderami), który pokazuje się w hookach
 * `displayQuinceBlock*` założonych w panelu modułu. Hook nie istnieje w kodzie
 * z góry, więc wyświetlanie idzie przez `__call()`.
 */
class Quince_Block extends Module
{
    public const DOMAIN_ADMIN = 'Modules.Quinceblock.Admin';

    /** Ustawienia modułu. Kasowane przy odinstalowaniu po nazwie, nigdy wzorcem. */
    public const SETTING_KEYS = [
        'QUINCE_BLOCK_CUSTOM_CSS',
        'QUINCE_BLOCK_LOW_STOCK',
        'QUINCE_BLOCK_REFRESH',
    ];

    public function __construct()
    {
        $this->name = 'quince_block';
        $this->tab = 'front_office_features';
        $this->version = '0.1.1';
        $this->author = 'Quince Project';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => '9.99.99'];
        $this->bootstrap = true;
        $this->dependencies = ['quince_engine'];

        // Jeden wpis w gałęzi „Quince project”, z trasą: rdzeń 9.2.1 odrzuca
        // zakładkę bez kontrolera i bez trasy. Wszystkie trasy panelu mają ten
        // sam `_legacy_controller`, więc uprawnienia sprawdza ta jedna zakładka.
        $this->tabs = [
            [
                'class_name' => 'AdminQuinceBlock',
                'visible' => true,
                'name' => 'Blocks',
                'parent_class_name' => 'AdminQuinceProject',
                'route_name' => 'quince_block_blocks',
                'wording' => 'Blocks',
                'wording_domain' => self::DOMAIN_ADMIN,
            ],
        ];

        parent::__construct();

        $this->displayName = $this->trans('Quince Block', [], self::DOMAIN_ADMIN);
        $this->description = $this->trans(
            'Content blocks with display rules, a schedule and A/B tests, shown in hooks of your own.',
            [],
            self::DOMAIN_ADMIN
        );
        $this->confirmUninstall = $this->trans(
            'Uninstall the module? All blocks, their rules and statistics will be removed.',
            [],
            self::DOMAIN_ADMIN
        );
    }

    public function install(): bool
    {
        return parent::install()
            && Schema::install()
            && $this->registerHook([
                'actionQuinceRegisterFeature',
                'displayHeader',
                'displayBeforeBodyClosingTag',
                'actionFrontControllerSetMedia',
                'filterCmsContent',
                'filterProductContent',
            ]);
    }

    public function uninstall(): bool
    {
        foreach (self::SETTING_KEYS as $key) {
            Configuration::deleteByName($key);
        }

        \Quince\Block\Front\BlockCache::clear();

        return Schema::uninstall() && parent::uninstall();
    }

    /** Przycisk „Konfiguruj” w menedżerze modułów prowadzi na listę bloków. */
    public function getContent(): void
    {
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminQuinceBlock'));
    }

    /** @return array<int,array<string,string>> */
    public function hookActionQuinceRegisterFeature(): array
    {
        return [[
            'type' => 'content_blocks',
            'id' => $this->name,
            'label' => $this->trans('Blocks', [], self::DOMAIN_ADMIN),
            'route' => 'quince_block_blocks',
        ]];
    }

    /** Kod do `<head>` z bloków, które w tym miejscu przechodzą swoje reguły. */
    public function hookDisplayHeader(array $params): string
    {
        return (new FrontRenderer($this))->headCode();
    }

    public function hookDisplayBeforeBodyClosingTag(array $params): string
    {
        return (new FrontRenderer($this))->footerCode();
    }

    public function hookActionFrontControllerSetMedia(array $params): void
    {
        (new FrontRenderer($this))->registerAssets($this->context->controller);
    }

    /** Shortcody `[quince_block id="12"]` w treści strony CMS. */
    public function hookFilterCmsContent(array $params): array
    {
        if (isset($params['object']['content']) && is_string($params['object']['content'])) {
            $params['object']['content'] = (new FrontRenderer($this))->parseShortcodes($params['object']['content']);
        }

        return ['object' => $params['object'] ?? null];
    }

    /** Shortcody w opisie i krótkim opisie produktu. */
    public function hookFilterProductContent(array $params): array
    {
        $renderer = new FrontRenderer($this);

        foreach (['description', 'description_short'] as $field) {
            if (isset($params['object'][$field]) && is_string($params['object'][$field])) {
                $params['object'][$field] = $renderer->parseShortcodes($params['object'][$field]);
            }
        }

        return ['object' => $params['object'] ?? null];
    }

    /**
     * Wyświetlenie bloków w hooku `displayQuinceBlock*`.
     *
     * Nazwy hooków zakłada sprzedawca w panelu, więc nie ma dla nich metod.
     *
     * @param string $method
     * @param array<int,mixed> $arguments
     */
    public function __call($method, $arguments)
    {
        $prefix = 'hook' . ucfirst(Schema::HOOK_PREFIX);

        if (stripos($method, $prefix) !== 0) {
            return '';
        }

        $hookName = lcfirst(substr($method, 4));
        $params = is_array($arguments[0] ?? null) ? $arguments[0] : [];

        return (new FrontRenderer($this))->renderHook($hookName, $params);
    }
}
