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

/**
 * 0.1.0 -> 0.1.1
 *
 * Tabela wyświetleń testu A/B już policzonych: wyświetlenie liczy się raz na
 * token i odwiedzającego, kliknięcie tylko po takim wyświetleniu.
 *
 * Samo zapytanie, bez klas modułu: w trakcie aktualizacji może być jeszcze
 * załadowana stara wersja kodu. Definicja musi zgadzać się z
 * `Quince\Block\Storage\Schema::abSeenTable()`.
 */
function upgrade_module_0_1_1(Module $module): bool
{
    return (bool) Db::getInstance()->execute(
        'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'quince_block_ab_seen` (
            `seen_key` CHAR(40) NOT NULL,
            `id_block` INT UNSIGNED NOT NULL,
            `clicked` TINYINT(1) NOT NULL DEFAULT 0,
            `date_add` INT UNSIGNED NOT NULL,
            PRIMARY KEY (`seen_key`),
            KEY `date_add` (`date_add`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;'
    );
}
