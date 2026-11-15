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
 * Reguły wyświetlania bloku. Każda operacja sprawdza przynależność reguły do bloku,
 * więc numer reguły z cudzego bloku niczego nie zmieni.
 */
final class RuleRepository extends AbstractRepository
{
    /**
     * @return array<int,array{id:int,type:string,mode:string,value:array<string,mixed>}>
     */
    public function forBlock(int $idBlock): array
    {
        $rows = $this->db()->executeS(
            'SELECT * FROM ' . $this->table(Schema::RULE) . ' WHERE id_block = ' . $idBlock . ' ORDER BY id_rule'
        ) ?: [];

        return array_map(fn (array $r): array => $this->map($r), $rows);
    }

    /** @return array{id:int,type:string,mode:string,value:array<string,mixed>}|null */
    public function find(int $idRule, int $idBlock): ?array
    {
        $row = $this->db()->getRow(
            'SELECT * FROM ' . $this->table(Schema::RULE) . ' WHERE id_rule = ' . $idRule . ' AND id_block = ' . $idBlock
        );

        return is_array($row) && $row ? $this->map($row) : null;
    }

    /**
     * @param array<string,mixed> $value
     *
     * @return int id reguły
     */
    public function save(int $idBlock, int $idRule, string $type, string $mode, array $value): int
    {
        $json = (string) json_encode($value, JSON_UNESCAPED_UNICODE);
        $mode = $mode === 'exclude' ? 'exclude' : 'include';

        if ($idRule > 0) {
            $this->run(
                'UPDATE ' . $this->table(Schema::RULE) . ' SET type = ' . $this->str($type) . ', mode = ' . $this->str($mode)
                . ', value = ' . $this->str($json) . ' WHERE id_rule = ' . $idRule . ' AND id_block = ' . $idBlock
            );

            return $idRule;
        }

        $this->run(
            'INSERT INTO ' . $this->table(Schema::RULE) . ' (id_block, type, mode, value) VALUES ('
            . $idBlock . ', ' . $this->str($type) . ', ' . $this->str($mode) . ', ' . $this->str($json) . ')'
        );

        return (int) $this->db()->Insert_ID();
    }

    public function delete(int $idRule, int $idBlock): void
    {
        $this->run('DELETE FROM ' . $this->table(Schema::RULE) . ' WHERE id_rule = ' . $idRule . ' AND id_block = ' . $idBlock);
    }

    public function countAll(): int
    {
        return (int) $this->db()->getValue('SELECT COUNT(*) FROM ' . $this->table(Schema::RULE));
    }

    /** @param array<string,mixed> $row */
    private function map(array $row): array
    {
        $value = json_decode((string) $row['value'], true);

        return [
            'id' => (int) $row['id_rule'],
            'type' => (string) $row['type'],
            'mode' => (string) $row['mode'] === 'exclude' ? 'exclude' : 'include',
            'value' => is_array($value) ? $value : [],
        ];
    }
}
