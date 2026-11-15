<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Block\Render;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Krótkie kody `[quince_block id="12"]` w treści strony CMS i opisie produktu.
 *
 * Edytor treści potrafi zamienić cudzysłów na `&quot;`, więc przyjmujemy
 * cudzysłów, apostrof, encję albo samą liczbę.
 */
final class Shortcodes
{
    private const PATTERN = '/\[quince_block\s+id=(?:&quot;|&#34;|"|\'|&#39;)?(\d{1,10})(?:&quot;|&#34;|"|\'|&#39;)?\s*\/?\]/i';

    public static function has(string $html): bool
    {
        return stripos($html, '[quince_block') !== false;
    }

    /**
     * @return array<int,int> id bloków w kolejności wystąpienia
     */
    public static function ids(string $html): array
    {
        if (!self::has($html) || !preg_match_all(self::PATTERN, $html, $m)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $m[1])));
    }

    /**
     * Zamienia każdy kod na wynik `$render(id)`.
     *
     * @param callable(int):string $render
     */
    public static function replace(string $html, callable $render): string
    {
        if (!self::has($html)) {
            return $html;
        }

        return (string) preg_replace_callback(
            self::PATTERN,
            static fn (array $m): string => (string) $render((int) $m[1]),
            $html
        );
    }
}
