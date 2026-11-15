<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Block\Rule;

use Configuration;
use DateTimeImmutable;
use DateTimeZone;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Harmonogram bloku: daty od/do, dni tygodnia, godziny.
 *
 * Wszystko w strefie czasowej sklepu (`PS_TIMEZONE`), bo w niej sprzedawca
 * wpisuje daty w panelu. Zakres godzin może przechodzić przez północ
 * (22:00 do 06:00).
 */
final class Schedule
{
    private static ?DateTimeImmutable $now = null;

    /**
     * @param array<string,mixed> $block wpis bloku z indeksu
     */
    public static function isOpen(array $block, ?DateTimeImmutable $now = null): bool
    {
        $now = $now ?? self::now();
        $stamp = $now->format('Y-m-d H:i:s');

        if (!empty($block['date_from']) && $stamp < (string) $block['date_from']) {
            return false;
        }

        if (!empty($block['date_to']) && $stamp > (string) $block['date_to']) {
            return false;
        }

        $days = (array) ($block['days'] ?? []);

        if ($days !== [] && !in_array((int) $now->format('N'), array_map('intval', $days), true)) {
            return false;
        }

        $from = (string) ($block['time_from'] ?? '');
        $to = (string) ($block['time_to'] ?? '');
        $time = $now->format('H:i:s');

        if ($from !== '' && $to !== '') {
            return $from <= $to
                ? ($time >= $from && $time <= $to)
                : ($time >= $from || $time <= $to);
        }

        if ($from !== '') {
            return $time >= $from;
        }

        if ($to !== '') {
            return $time <= $to;
        }

        return true;
    }

    /** Bieżąca chwila w strefie sklepu, jedna na żądanie. */
    public static function now(): DateTimeImmutable
    {
        if (self::$now === null) {
            $zone = (string) Configuration::get('PS_TIMEZONE');

            try {
                $tz = new DateTimeZone($zone !== '' ? $zone : date_default_timezone_get());
            } catch (\Exception $e) {
                $tz = new DateTimeZone(date_default_timezone_get());
            }

            self::$now = new DateTimeImmutable('now', $tz);
        }

        return self::$now;
    }
}
