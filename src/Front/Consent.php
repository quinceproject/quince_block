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

use Module;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Zgoda odwiedzającego na pomiar, przez publiczne API `quince_cmp`
 * (`allowed()`), nigdy przez odczyt jego ciasteczka.
 *
 * Z `quince_cmp`: statystyki A/B i ciasteczka (wariant, UTM) dopiero po zgodzie
 * na kategorię analityczną. Bez `quince_cmp`: żadnych ciasteczek, a statystyki
 * liczą się anonimowo, z wariantem losowanym przy każdym wyświetleniu.
 */
final class Consent
{
    /** Kod kategorii w `quince_cmp`. */
    public const CODE = 'cookies_analytics';

    public const AB_COOKIE_PREFIX = 'qb_ab_';
    public const UTM_COOKIE = 'qb_utm';

    private static ?bool $cmp = null;
    private static ?bool $granted = null;

    /** Czy w sklepie działa moduł zgód. */
    public static function cmpActive(): bool
    {
        if (self::$cmp === null) {
            self::$cmp = Module::isEnabled('quince_cmp');
        }

        return self::$cmp;
    }

    /** Zgoda na kategorię analityczną. Bez modułu zgód zawsze fałsz. */
    public static function analyticsGranted(): bool
    {
        if (self::$granted === null) {
            self::$granted = false;

            if (self::cmpActive()) {
                $cmp = Module::getInstanceByName('quince_cmp');
                self::$granted = $cmp && method_exists($cmp, 'allowed') && (bool) $cmp->allowed(self::CODE);
            }
        }

        return self::$granted;
    }

    /** Czy wolno liczyć wyświetlenia i kliknięcia. */
    public static function trackingAllowed(): bool
    {
        return !self::cmpActive() || self::analyticsGranted();
    }

    /** Czy wolno czytać i zapisywać ciasteczka modułu. */
    public static function cookiesAllowed(): bool
    {
        return self::cmpActive() && self::analyticsGranted();
    }
}
