<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Block\Storage;

use Db;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Tabele modułu.
 *
 * Blok ma jeden tytuł do rozpoznania w panelu, a treść osobno dla każdego sklepu
 * i języka. O tym, w których sklepach blok się pokazuje, decyduje `block_shop`,
 * nie obecność wiersza treści.
 */
final class Schema
{
    public const BLOCK = 'quince_block';
    public const BLOCK_SHOP = 'quince_block_shop';
    public const BLOCK_LANG = 'quince_block_lang';
    public const HOOK = 'quince_block_hook';
    public const BLOCK_HOOK = 'quince_block_block_hook';
    public const RULE = 'quince_block_rule';
    public const STATS = 'quince_block_stats';
    public const VERSION = 'quince_block_version';

    /** Przedrostek nazw hooków, w które moduł wyświetla bloki. */
    public const HOOK_PREFIX = 'displayQuinceBlock';

    public static function install(): bool
    {
        $p = _DB_PREFIX_;
        $e = _MYSQL_ENGINE_;
        $sql = [];

        $sql[] = "CREATE TABLE IF NOT EXISTS `{$p}" . self::BLOCK . "` (
            `id_block` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `title` VARCHAR(255) NOT NULL DEFAULT '',
            `active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            `position` INT UNSIGNED NOT NULL DEFAULT 0,
            `rule_logic` VARCHAR(3) NOT NULL DEFAULT 'and',
            `date_from` DATETIME NULL DEFAULT NULL,
            `date_to` DATETIME NULL DEFAULT NULL,
            `days` VARCHAR(20) NOT NULL DEFAULT '',
            `time_from` TIME NULL DEFAULT NULL,
            `time_to` TIME NULL DEFAULT NULL,
            `auto_refresh` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            `lazy_load` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            `ab_enabled` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            `ab_auto_optimize` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            `ab_min_views` INT UNSIGNED NOT NULL DEFAULT 100,
            `preview_token` VARCHAR(64) NOT NULL DEFAULT '',
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_block`),
            KEY `active` (`active`)
        ) ENGINE={$e} DEFAULT CHARSET=utf8mb4;";

        $sql[] = "CREATE TABLE IF NOT EXISTS `{$p}" . self::BLOCK_SHOP . "` (
            `id_block` INT UNSIGNED NOT NULL,
            `id_shop` INT UNSIGNED NOT NULL,
            PRIMARY KEY (`id_block`, `id_shop`),
            KEY `id_shop` (`id_shop`)
        ) ENGINE={$e} DEFAULT CHARSET=utf8mb4;";

        $sql[] = "CREATE TABLE IF NOT EXISTS `{$p}" . self::BLOCK_LANG . "` (
            `id_block` INT UNSIGNED NOT NULL,
            `id_shop` INT UNSIGNED NOT NULL,
            `id_lang` INT UNSIGNED NOT NULL,
            `content` MEDIUMTEXT NULL,
            `content_b` MEDIUMTEXT NULL,
            `head_code` MEDIUMTEXT NULL,
            `footer_code` MEDIUMTEXT NULL,
            PRIMARY KEY (`id_block`, `id_shop`, `id_lang`)
        ) ENGINE={$e} DEFAULT CHARSET=utf8mb4;";

        $sql[] = "CREATE TABLE IF NOT EXISTS `{$p}" . self::HOOK . "` (
            `id_hook` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(64) NOT NULL,
            `description` VARCHAR(255) NOT NULL DEFAULT '',
            `active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (`id_hook`),
            UNIQUE KEY `name` (`name`)
        ) ENGINE={$e} DEFAULT CHARSET=utf8mb4;";

        $sql[] = "CREATE TABLE IF NOT EXISTS `{$p}" . self::BLOCK_HOOK . "` (
            `id_block` INT UNSIGNED NOT NULL,
            `id_hook` INT UNSIGNED NOT NULL,
            PRIMARY KEY (`id_block`, `id_hook`),
            KEY `id_hook` (`id_hook`)
        ) ENGINE={$e} DEFAULT CHARSET=utf8mb4;";

        $sql[] = "CREATE TABLE IF NOT EXISTS `{$p}" . self::RULE . "` (
            `id_rule` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_block` INT UNSIGNED NOT NULL,
            `type` VARCHAR(32) NOT NULL,
            `mode` VARCHAR(7) NOT NULL DEFAULT 'include',
            `value` TEXT NULL,
            PRIMARY KEY (`id_rule`),
            KEY `id_block` (`id_block`)
        ) ENGINE={$e} DEFAULT CHARSET=utf8mb4;";

        $sql[] = "CREATE TABLE IF NOT EXISTS `{$p}" . self::STATS . "` (
            `id_block` INT UNSIGNED NOT NULL,
            `id_shop` INT UNSIGNED NOT NULL,
            `variant` CHAR(1) NOT NULL DEFAULT 'a',
            `day` DATE NOT NULL,
            `views` INT UNSIGNED NOT NULL DEFAULT 0,
            `clicks` INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id_block`, `id_shop`, `variant`, `day`)
        ) ENGINE={$e} DEFAULT CHARSET=utf8mb4;";

        $sql[] = "CREATE TABLE IF NOT EXISTS `{$p}" . self::VERSION . "` (
            `id_version` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_block` INT UNSIGNED NOT NULL,
            `id_employee` INT UNSIGNED NOT NULL DEFAULT 0,
            `date_add` DATETIME NOT NULL,
            `snapshot` MEDIUMTEXT NOT NULL,
            PRIMARY KEY (`id_version`),
            KEY `id_block` (`id_block`)
        ) ENGINE={$e} DEFAULT CHARSET=utf8mb4;";

        foreach ($sql as $query) {
            if (!Db::getInstance()->execute($query)) {
                return false;
            }
        }

        return true;
    }

    public static function uninstall(): bool
    {
        $p = _DB_PREFIX_;
        $ok = true;

        foreach ([self::BLOCK, self::BLOCK_SHOP, self::BLOCK_LANG, self::HOOK, self::BLOCK_HOOK, self::RULE, self::STATS, self::VERSION] as $table) {
            $ok = Db::getInstance()->execute("DROP TABLE IF EXISTS `{$p}{$table}`") && $ok;
        }

        return $ok;
    }
}
