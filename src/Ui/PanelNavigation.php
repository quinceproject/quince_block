<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Block\Ui;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Pozycje bocznego paska panelu modułu. Napisy po angielsku, tłumaczy je szablon.
 * Ikony z zestawu silnika (`_macros/icons.html.twig`).
 */
final class PanelNavigation
{
    private const ITEMS = [
        ['key' => 'blocks', 'label' => 'Blocks', 'icon' => 'layout', 'route' => 'quince_block_blocks'],
        ['key' => 'hooks', 'label' => 'Hooks', 'icon' => 'link', 'route' => 'quince_block_hooks'],
        ['key' => 'stats', 'label' => 'Statistics', 'icon' => 'eye', 'route' => 'quince_block_stats'],
        ['key' => 'tools', 'label' => 'Tools', 'icon' => 'box', 'route' => 'quince_block_tools'],
        ['key' => 'settings', 'label' => 'Settings', 'icon' => 'settings', 'route' => 'quince_block_settings'],
    ];

    /** @return array<int,array{key:string,label:string,icon:string,route:string}> */
    public function items(): array
    {
        return self::ITEMS;
    }

    /** Nazwa ekranu po kluczu pozycji ('' gdy klucz nie należy do paska). */
    public function label(string $key): string
    {
        foreach (self::ITEMS as $item) {
            if ($item['key'] === $key) {
                return $item['label'];
            }
        }

        return '';
    }
}
