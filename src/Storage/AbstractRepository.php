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
 * Podstawa repozytoriów panelu: nazwy tabel i składanie wartości SQL.
 *
 * `Db::insert()` nie escapuje wartości, więc zapytania składamy sami: tekst
 * zawsze przez `pSQL()`, liczby przez rzutowanie. Treść bloku to HTML ze
 * skryptami, dlatego `pSQL(..., true)`, które nie wycina znaczników.
 */
abstract class AbstractRepository
{
    protected function db(): Db
    {
        return Db::getInstance();
    }

    protected function table(string $name): string
    {
        return '`' . _DB_PREFIX_ . $name . '`';
    }

    /** Tekst w apostrofach albo NULL. */
    protected function str(?string $value, bool $nullable = false): string
    {
        if ($value === null || ($nullable && $value === '')) {
            return 'NULL';
        }

        return "'" . pSQL($value, true) . "'";
    }

    /** @param int[] $ids */
    protected function idList(array $ids): string
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));

        return $ids === [] ? '0' : implode(',', $ids);
    }

    /**
     * Zapis kilku zapytań jako jedna całość. Błąd w środku cofa wszystko.
     *
     * @param callable():void $work rzuca wyjątek, gdy coś poszło źle
     */
    protected function transaction(callable $work): void
    {
        $db = $this->db();
        $db->execute('START TRANSACTION');

        try {
            $work();
            $db->execute('COMMIT');
        } catch (\Throwable $e) {
            $db->execute('ROLLBACK');

            throw $e;
        }
    }

    /** Zapytanie, które musi się udać: błąd przerywa transakcję. */
    protected function run(string $sql): void
    {
        if (!$this->db()->execute($sql)) {
            throw new \RuntimeException('Database error: ' . $this->db()->getMsgError());
        }
    }

    protected function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
