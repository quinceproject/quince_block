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

use Cache;
use Quince\Block\Storage\BlockReader;
use Shop;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Pamięć podręczna frontu między żądaniami.
 *
 * Gdy sklep ma włączony `Cache` rdzenia (Memcached, APCu, Redis), wartości idą
 * tam. Bez niego lądują jako pliki JSON w `var/cache/<środowisko>/quince_block/`.
 * JSON, a nie plik PHP: przy `opcache.validate_timestamps=0` serwer czytałby
 * starą wersję pliku PHP aż do restartu.
 *
 * Panel woła `clear()` po każdym zapisie bloku, hooka, reguły i ustawień.
 */
final class BlockCache
{
    private const PREFIX = 'quince_block_';

    /** @var array<string,mixed> wartości odczytane w tym żądaniu */
    private static array $local = [];

    /**
     * Wartość spod klucza albo null, gdy jej nie ma albo wygasła.
     *
     * @return mixed
     */
    public static function get(string $key)
    {
        if (array_key_exists($key, self::$local)) {
            return self::$local[$key];
        }

        $value = self::useCore() ? self::coreGet($key) : self::fileGet($key);

        if ($value !== null) {
            self::$local[$key] = $value;
        }

        return $value;
    }

    /**
     * @param mixed $value wartość, którą da się zapisać jako JSON
     * @param int $ttl czas życia w sekundach, 0 = do wyczyszczenia
     */
    public static function set(string $key, $value, int $ttl = 0): void
    {
        self::$local[$key] = $value;

        if (self::useCore()) {
            Cache::getInstance()->set(self::PREFIX . $key, $value, $ttl);

            return;
        }

        self::fileSet($key, $value, $ttl);
    }

    /**
     * Czyści wszystko, co front zapamiętał. Do wołania z panelu po zapisie.
     */
    public static function clear(): void
    {
        self::$local = [];
        BlockReader::reset();

        if (self::useCore()) {
            $keys = [];

            foreach (Shop::getShops(false, null, true) as $idShop) {
                $keys[] = self::PREFIX . 'index_' . (int) $idShop;
                $keys[] = self::PREFIX . 'ab_' . (int) $idShop;
            }

            if ($keys !== []) {
                Cache::getInstance()->deleteMulti($keys);
            }
        }

        // Pliki czyścimy zawsze: panel i sklep mogą działać w innych
        // środowiskach (prod, dev), a każde ma swój katalog cache.
        foreach (glob(_PS_ROOT_DIR_ . '/var/cache/*/quince_block/*.json') ?: [] as $file) {
            @unlink($file);
        }
    }

    private static function useCore(): bool
    {
        return defined('_PS_CACHE_ENABLED_') && _PS_CACHE_ENABLED_ && Cache::getInstance() !== null;
    }

    /** @return mixed */
    private static function coreGet(string $key)
    {
        $cache = Cache::getInstance();
        $full = self::PREFIX . $key;

        if (!$cache->exists($full)) {
            return null;
        }

        $value = $cache->get($full);

        return $value === false ? null : $value;
    }

    private static function path(string $key): string
    {
        return _PS_CACHE_DIR_ . 'quince_block/' . preg_replace('/[^a-z0-9_]/i', '_', $key) . '.json';
    }

    /** @return mixed */
    private static function fileGet(string $key)
    {
        $file = self::path($key);

        if (!is_file($file)) {
            return null;
        }

        $data = json_decode((string) @file_get_contents($file), true);

        if (!is_array($data) || !array_key_exists('v', $data)) {
            return null;
        }

        if ((int) ($data['e'] ?? 0) > 0 && (int) $data['e'] < time()) {
            return null;
        }

        return $data['v'];
    }

    /** @param mixed $value */
    private static function fileSet(string $key, $value, int $ttl): void
    {
        $file = self::path($key);
        $dir = dirname($file);

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        $json = json_encode(['e' => $ttl > 0 ? time() + $ttl : 0, 'v' => $value], JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            return;
        }

        // Zapis do pliku tymczasowego i podmiana: równoległe żądanie nie
        // przeczyta pliku zapisanego do połowy.
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (@file_put_contents($tmp, $json) !== false) {
            @rename($tmp, $file);
        } else {
            @unlink($tmp);
        }
    }
}
