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

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Bloki w panelu: lista, odczyt do edycji, zapis z treściami, sklepami i hookami,
 * duplikowanie, usuwanie, migawka do historii i jej przywrócenie.
 *
 * Treść i przypisania do sklepów zapisujemy wyłącznie dla sklepów z kontekstu
 * pracownika. Wiersze pozostałych sklepów zostają takie, jakie były.
 */
final class BlockRepository extends AbstractRepository
{
    /** Pola wiersza bloku, które ustawia panel. */
    public const ROW_FIELDS = [
        'title', 'active', 'position', 'rule_logic', 'date_from', 'date_to', 'days',
        'time_from', 'time_to', 'auto_refresh', 'lazy_load', 'ab_enabled',
        'ab_auto_optimize', 'ab_min_views',
    ];

    public const CONTENT_FIELDS = ['content', 'content_b', 'head_code', 'footer_code'];

    private const INT_FIELDS = ['active', 'position', 'auto_refresh', 'lazy_load', 'ab_enabled', 'ab_auto_optimize', 'ab_min_views'];

    private const NULLABLE_FIELDS = ['date_from', 'date_to', 'time_from', 'time_to'];

    /**
     * @return array<int,array<string,mixed>>
     */
    public function search(string $query, string $status, int $offset, int $limit): array
    {
        $rows = $this->db()->executeS(
            'SELECT * FROM ' . $this->table(Schema::BLOCK) . $this->where($query, $status)
            . ' ORDER BY id_block DESC LIMIT ' . max(0, $offset) . ', ' . max(1, $limit)
        );

        return is_array($rows) ? $rows : [];
    }

    public function count(string $query, string $status): int
    {
        return (int) $this->db()->getValue(
            'SELECT COUNT(*) FROM ' . $this->table(Schema::BLOCK) . $this->where($query, $status)
        );
    }

    public function countAll(): int
    {
        return (int) $this->db()->getValue('SELECT COUNT(*) FROM ' . $this->table(Schema::BLOCK));
    }

    /** @return array<int,array{id:int,title:string}> wszystkie bloki do list wyboru */
    public function choices(): array
    {
        $rows = $this->db()->executeS(
            'SELECT id_block, title FROM ' . $this->table(Schema::BLOCK) . ' ORDER BY id_block DESC'
        ) ?: [];

        return array_map(static fn (array $r): array => ['id' => (int) $r['id_block'], 'title' => (string) $r['title']], $rows);
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $row = $this->db()->getRow('SELECT * FROM ' . $this->table(Schema::BLOCK) . ' WHERE id_block = ' . $id);

        return is_array($row) && $row ? $row : null;
    }

    /**
     * Treści bloku: [id_shop][id_lang][pole].
     *
     * @return array<int,array<int,array<string,string>>>
     */
    public function contents(int $id): array
    {
        $rows = $this->db()->executeS(
            'SELECT * FROM ' . $this->table(Schema::BLOCK_LANG) . ' WHERE id_block = ' . $id
        ) ?: [];
        $out = [];

        foreach ($rows as $row) {
            foreach (self::CONTENT_FIELDS as $field) {
                $out[(int) $row['id_shop']][(int) $row['id_lang']][$field] = (string) $row[$field];
            }
        }

        return $out;
    }

    /** @return int[] */
    public function shopIds(int $id): array
    {
        $rows = $this->db()->executeS(
            'SELECT id_shop FROM ' . $this->table(Schema::BLOCK_SHOP) . ' WHERE id_block = ' . $id
        ) ?: [];

        return array_map(static fn (array $r): int => (int) $r['id_shop'], $rows);
    }

    /** @return int[] */
    public function hookIds(int $id): array
    {
        $rows = $this->db()->executeS(
            'SELECT id_hook FROM ' . $this->table(Schema::BLOCK_HOOK) . ' WHERE id_block = ' . $id
        ) ?: [];

        return array_map(static fn (array $r): int => (int) $r['id_hook'], $rows);
    }

    /**
     * Nazwy hooków dla wielu bloków naraz (jedno zapytanie na listę).
     *
     * @param int[] $ids
     *
     * @return array<int,string[]>
     */
    public function hookNamesFor(array $ids): array
    {
        $rows = $this->db()->executeS(
            'SELECT bh.id_block, h.name, h.active FROM ' . $this->table(Schema::BLOCK_HOOK) . ' bh'
            . ' INNER JOIN ' . $this->table(Schema::HOOK) . ' h ON h.id_hook = bh.id_hook'
            . ' WHERE bh.id_block IN (' . $this->idList($ids) . ') ORDER BY h.name'
        ) ?: [];
        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row['id_block']][] = (string) $row['name'];
        }

        return $out;
    }

    /**
     * @param int[] $ids
     *
     * @return array<int,int[]>
     */
    public function shopIdsFor(array $ids): array
    {
        $rows = $this->db()->executeS(
            'SELECT id_block, id_shop FROM ' . $this->table(Schema::BLOCK_SHOP)
            . ' WHERE id_block IN (' . $this->idList($ids) . ')'
        ) ?: [];
        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row['id_block']][] = (int) $row['id_shop'];
        }

        return $out;
    }

    /**
     * Zapis bloku.
     *
     * @param array<string,mixed> $row pola z ROW_FIELDS, już sprawdzone
     * @param array<int,array<int,array<string,string>>> $contents [id_shop][id_lang][pole]
     * @param int[] $shopIds sklepy, w których blok ma się pokazywać (z kontekstu)
     * @param int[] $contextShopIds sklepy, którymi pracownik teraz zarządza
     * @param int[] $hookIds
     *
     * @return int id bloku
     */
    public function save(int $id, array $row, array $contents, array $shopIds, array $contextShopIds, array $hookIds): int
    {
        $this->transaction(function () use (&$id, $row, $contents, $shopIds, $contextShopIds, $hookIds): void {
            $id = $this->writeRow($id, $row);
            $this->writeContents($id, $contents, $contextShopIds);
            $this->writeShops($id, $shopIds, $contextShopIds);
            $this->writeHooks($id, $hookIds);
        });

        return $id;
    }

    /** @param int[] $ids */
    public function setActive(array $ids, bool $active): void
    {
        $this->run(
            'UPDATE ' . $this->table(Schema::BLOCK) . ' SET active = ' . ($active ? 1 : 0)
            . ", date_upd = '" . $this->now() . "' WHERE id_block IN (" . $this->idList($ids) . ')'
        );
    }

    public function setPreviewToken(int $id, string $token): void
    {
        $this->run(
            'UPDATE ' . $this->table(Schema::BLOCK) . ' SET preview_token = ' . $this->str($token)
            . ' WHERE id_block = ' . $id
        );
    }

    /**
     * Usuwa bloki razem ze wszystkim, co do nich należy: treściami, sklepami,
     * hookami, regułami, statystykami i historią.
     *
     * @param int[] $ids
     */
    public function delete(array $ids): void
    {
        $list = $this->idList($ids);

        $this->transaction(function () use ($list): void {
            foreach ([Schema::BLOCK_SHOP, Schema::BLOCK_LANG, Schema::BLOCK_HOOK, Schema::RULE, Schema::STATS, Schema::VERSION, Schema::BLOCK] as $table) {
                $this->run('DELETE FROM ' . $this->table($table) . ' WHERE id_block IN (' . $list . ')');
            }
        });
    }

    /**
     * Kopia bloku: wiersz, treści, sklepy, hooki i reguły. Bez statystyk i historii.
     * Kopia startuje wyłączona, żeby nie pokazała się w sklepie obok oryginału.
     */
    public function duplicate(int $id, string $titleSuffix): int
    {
        $source = $this->find($id);

        if ($source === null) {
            return 0;
        }

        $newId = 0;

        $this->transaction(function () use ($id, $source, $titleSuffix, &$newId): void {
            $row = array_intersect_key($source, array_flip(self::ROW_FIELDS));
            $row['title'] = mb_substr(trim((string) $source['title'] . $titleSuffix), 0, 255);
            $row['active'] = 0;
            $newId = $this->writeRow(0, $row);

            $this->copyRows(Schema::BLOCK_SHOP, ['id_shop'], $id, $newId);
            $this->copyRows(Schema::BLOCK_LANG, ['id_shop', 'id_lang', 'content', 'content_b', 'head_code', 'footer_code'], $id, $newId);
            $this->copyRows(Schema::BLOCK_HOOK, ['id_hook'], $id, $newId);
            $this->copyRows(Schema::RULE, ['type', 'mode', 'value'], $id, $newId);
        });

        return $newId;
    }

    /**
     * Migawka całego bloku do historii.
     *
     * @return array<string,mixed>
     */
    public function snapshot(int $id): array
    {
        $row = $this->find($id) ?? [];
        $rules = $this->db()->executeS(
            'SELECT type, mode, value FROM ' . $this->table(Schema::RULE) . ' WHERE id_block = ' . $id . ' ORDER BY id_rule'
        ) ?: [];
        $contents = [];

        foreach ($this->contents($id) as $idShop => $langs) {
            foreach ($langs as $idLang => $fields) {
                $contents[] = ['id_shop' => $idShop, 'id_lang' => $idLang] + $fields;
            }
        }

        return [
            'row' => array_intersect_key($row, array_flip(self::ROW_FIELDS)),
            'contents' => $contents,
            'shops' => $this->shopIds($id),
            'hooks' => $this->hookIds($id),
            'rules' => array_map(static fn (array $r): array => [
                'type' => (string) $r['type'],
                'mode' => (string) $r['mode'],
                'value' => (string) $r['value'],
            ], $rules),
        ];
    }

    /**
     * Przywraca blok z migawki. Wiersz, hooki i reguły są wspólne dla sklepów,
     * treść i przypisanie do sklepu wracają tylko w sklepach z kontekstu.
     *
     * @param array<string,mixed> $snapshot
     * @param int[] $contextShopIds
     */
    public function restore(int $id, array $snapshot, array $contextShopIds, array $existingHookIds): void
    {
        $row = array_intersect_key((array) ($snapshot['row'] ?? []), array_flip(self::ROW_FIELDS));
        $contents = [];

        foreach ((array) ($snapshot['contents'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $fields = [];

            foreach (self::CONTENT_FIELDS as $field) {
                $fields[$field] = (string) ($item[$field] ?? '');
            }

            $contents[(int) ($item['id_shop'] ?? 0)][(int) ($item['id_lang'] ?? 0)] = $fields;
        }

        $shops = array_map('intval', (array) ($snapshot['shops'] ?? []));
        // Hook usunięty od czasu migawki nie wraca do bloku.
        $hooks = array_values(array_intersect(array_map('intval', (array) ($snapshot['hooks'] ?? [])), $existingHookIds));
        $rules = (array) ($snapshot['rules'] ?? []);

        $this->transaction(function () use ($id, $row, $contents, $shops, $contextShopIds, $hooks, $rules): void {
            $this->writeRow($id, $row);
            // Język albo sklep, którego w migawce nie było, dostaje pustą treść:
            // przywrócenie ma dać dokładnie stan z migawki.
            $this->run(
                'DELETE FROM ' . $this->table(Schema::BLOCK_LANG) . ' WHERE id_block = ' . $id
                . ' AND id_shop IN (' . $this->idList($contextShopIds) . ')'
            );
            $this->writeContents($id, $contents, $contextShopIds);
            $this->writeShops($id, $shops, $contextShopIds);
            $this->writeHooks($id, $hooks);

            $this->run('DELETE FROM ' . $this->table(Schema::RULE) . ' WHERE id_block = ' . $id);

            foreach ($rules as $rule) {
                if (!is_array($rule)) {
                    continue;
                }

                $this->run(
                    'INSERT INTO ' . $this->table(Schema::RULE) . ' (id_block, type, mode, value) VALUES ('
                    . $id . ', ' . $this->str((string) ($rule['type'] ?? '')) . ', '
                    . $this->str(($rule['mode'] ?? '') === 'exclude' ? 'exclude' : 'include') . ', '
                    . $this->str((string) ($rule['value'] ?? '{}')) . ')'
                );
            }
        });
    }

    /**
     * Wiersz bloku. Nowy blok, gdy `$id` = 0.
     *
     * @param array<string,mixed> $row
     */
    public function writeRow(int $id, array $row): int
    {
        $sets = [];

        foreach (self::ROW_FIELDS as $field) {
            if (!array_key_exists($field, $row)) {
                continue;
            }

            $value = $row[$field];

            if (in_array($field, self::INT_FIELDS, true)) {
                $sets[$field] = (string) (int) $value;
            } elseif (in_array($field, self::NULLABLE_FIELDS, true)) {
                $sets[$field] = $this->str($value === null ? null : (string) $value, true);
            } else {
                $sets[$field] = $this->str((string) $value);
            }
        }

        $now = "'" . $this->now() . "'";
        $sets['date_upd'] = $now;

        if ($id > 0) {
            $assign = [];

            foreach ($sets as $field => $value) {
                $assign[] = '`' . $field . '` = ' . $value;
            }

            $this->run('UPDATE ' . $this->table(Schema::BLOCK) . ' SET ' . implode(', ', $assign) . ' WHERE id_block = ' . $id);

            return $id;
        }

        $sets['date_add'] = $now;
        $this->run(
            'INSERT INTO ' . $this->table(Schema::BLOCK) . ' (`' . implode('`, `', array_keys($sets)) . '`) VALUES ('
            . implode(', ', $sets) . ')'
        );

        return (int) $this->db()->Insert_ID();
    }

    /**
     * @param array<int,array<int,array<string,string>>> $contents
     * @param int[] $contextShopIds
     */
    private function writeContents(int $id, array $contents, array $contextShopIds): void
    {
        foreach ($contents as $idShop => $langs) {
            if (!in_array((int) $idShop, $contextShopIds, true) || !is_array($langs)) {
                continue;
            }

            foreach ($langs as $idLang => $fields) {
                if ((int) $idLang <= 0 || !is_array($fields)) {
                    continue;
                }

                $values = [];
                $updates = [];

                foreach (self::CONTENT_FIELDS as $field) {
                    $values[] = $this->str((string) ($fields[$field] ?? ''));
                    $updates[] = '`' . $field . '` = VALUES(`' . $field . '`)';
                }

                $this->run(
                    'INSERT INTO ' . $this->table(Schema::BLOCK_LANG)
                    . ' (id_block, id_shop, id_lang, `' . implode('`, `', self::CONTENT_FIELDS) . '`) VALUES ('
                    . $id . ', ' . (int) $idShop . ', ' . (int) $idLang . ', ' . implode(', ', $values) . ')'
                    . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates)
                );
            }
        }
    }

    /**
     * @param int[] $shopIds
     * @param int[] $contextShopIds
     */
    private function writeShops(int $id, array $shopIds, array $contextShopIds): void
    {
        $this->run(
            'DELETE FROM ' . $this->table(Schema::BLOCK_SHOP) . ' WHERE id_block = ' . $id
            . ' AND id_shop IN (' . $this->idList($contextShopIds) . ')'
        );

        foreach (array_unique(array_map('intval', $shopIds)) as $idShop) {
            if (!in_array($idShop, $contextShopIds, true)) {
                continue;
            }

            $this->run(
                'INSERT IGNORE INTO ' . $this->table(Schema::BLOCK_SHOP) . ' (id_block, id_shop) VALUES (' . $id . ', ' . $idShop . ')'
            );
        }
    }

    /** @param int[] $hookIds */
    private function writeHooks(int $id, array $hookIds): void
    {
        $this->run('DELETE FROM ' . $this->table(Schema::BLOCK_HOOK) . ' WHERE id_block = ' . $id);

        foreach (array_unique(array_map('intval', $hookIds)) as $idHook) {
            if ($idHook <= 0) {
                continue;
            }

            $this->run(
                'INSERT IGNORE INTO ' . $this->table(Schema::BLOCK_HOOK) . ' (id_block, id_hook) VALUES (' . $id . ', ' . $idHook . ')'
            );
        }
    }

    /**
     * Kopiuje wiersze tabeli podrzędnej z bloku na blok, wartość po wartości przez pSQL.
     *
     * @param string[] $columns
     */
    private function copyRows(string $table, array $columns, int $from, int $to): void
    {
        $rows = $this->db()->executeS(
            'SELECT `' . implode('`, `', $columns) . '` FROM ' . $this->table($table) . ' WHERE id_block = ' . $from
        ) ?: [];

        foreach ($rows as $row) {
            $values = [];

            foreach ($columns as $column) {
                $values[] = str_starts_with($column, 'id_') ? (string) (int) $row[$column] : $this->str((string) $row[$column]);
            }

            $this->run(
                'INSERT INTO ' . $this->table($table) . ' (id_block, `' . implode('`, `', $columns) . '`) VALUES ('
                . $to . ', ' . implode(', ', $values) . ')'
            );
        }
    }

    private function where(string $query, string $status): string
    {
        $where = [];
        $query = trim($query);

        if ($query !== '') {
            if (ctype_digit($query)) {
                $where[] = '(id_block = ' . (int) $query . " OR title LIKE '%" . pSQL(addcslashes($query, '%_')) . "%')";
            } else {
                $where[] = "title LIKE '%" . pSQL(addcslashes($query, '%_')) . "%'";
            }
        }

        if ($status === 'active') {
            $where[] = 'active = 1';
        } elseif ($status === 'inactive') {
            $where[] = 'active = 0';
        }

        return $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
    }
}
