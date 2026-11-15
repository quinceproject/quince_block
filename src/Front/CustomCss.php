<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Block\Front;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Zapis własnego CSS sklepu w konfiguracji.
 *
 * Konfiguracja PrestaShopa przepuszcza wartość przez filtr HTML albo obcina
 * znaczniki, a jedno i drugie psuje CSS (`>` w selektorze staje się `&gt;`).
 * Dlatego trzymamy go zakodowanego w base64, z przedrostkiem.
 */
final class CustomCss
{
    public const KEY = 'QUINCE_BLOCK_CUSTOM_CSS';

    private const PREFIX = 'b64:';

    public static function encode(string $css): string
    {
        $css = trim($css);

        return $css === '' ? '' : self::PREFIX . base64_encode($css);
    }

    public static function decode(string $stored): string
    {
        if (!str_starts_with($stored, self::PREFIX)) {
            return $stored;
        }

        $css = base64_decode(substr($stored, strlen(self::PREFIX)), true);

        return $css === false ? '' : $css;
    }
}
