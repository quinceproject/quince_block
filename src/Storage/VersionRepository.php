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
 * Historia zmian bloku: migawka całego bloku sprzed każdej zmiany.
 */
final class VersionRepository extends AbstractRepository
{
    /** Ile wersji trzymamy na blok; starsze wypadają przy zapisie nowej. */
    public const KEEP = 50;

    /** @param array<string,mixed> $snapshot */
    public function add(int $idBlock, int $idEmployee, array $snapshot): void
    {
        $this->run(
            'INSERT INTO ' . $this->table(Schema::VERSION) . ' (id_block, id_employee, date_add, snapshot) VALUES ('
            . $idBlock . ', ' . $idEmployee . ", '" . $this->now() . "', "
            . $this->str((string) json_encode($snapshot, JSON_UNESCAPED_UNICODE)) . ')'
        );

        // executeS, nie getValue: getValue dokleja własne `LIMIT 1` i zapytanie
        // z przesunięciem staje się błędnym SQL.
        $rows = $this->db()->executeS(
            'SELECT id_version FROM ' . $this->table(Schema::VERSION) . ' WHERE id_block = ' . $idBlock
            . ' ORDER BY id_version DESC LIMIT ' . (self::KEEP - 1) . ', 1'
        );
        $keepFrom = is_array($rows) && isset($rows[0]['id_version']) ? (int) $rows[0]['id_version'] : 0;

        if ($keepFrom > 0) {
            $this->run(
                'DELETE FROM ' . $this->table(Schema::VERSION) . ' WHERE id_block = ' . $idBlock . ' AND id_version < ' . $keepFrom
            );
        }
    }

    /**
     * @return array<int,array{id:int,date:string,employee:string,title:string,rules:int}>
     */
    public function forBlock(int $idBlock): array
    {
        $rows = $this->db()->executeS(
            'SELECT v.id_version, v.date_add, v.snapshot, e.firstname, e.lastname FROM ' . $this->table(Schema::VERSION) . ' v'
            . ' LEFT JOIN `' . _DB_PREFIX_ . 'employee` e ON e.id_employee = v.id_employee'
            . ' WHERE v.id_block = ' . $idBlock . ' ORDER BY v.id_version DESC'
        ) ?: [];

        return array_map(static function (array $r): array {
            $snapshot = json_decode((string) $r['snapshot'], true);

            return [
                'id' => (int) $r['id_version'],
                'date' => (string) $r['date_add'],
                'employee' => trim((string) $r['firstname'] . ' ' . (string) $r['lastname']),
                'title' => (string) ($snapshot['row']['title'] ?? ''),
                'rules' => is_array($snapshot['rules'] ?? null) ? count($snapshot['rules']) : 0,
            ];
        }, $rows);
    }

    /** @return array<string,mixed>|null migawka wersji należącej do bloku */
    public function snapshot(int $idVersion, int $idBlock): ?array
    {
        $json = $this->db()->getValue(
            'SELECT snapshot FROM ' . $this->table(Schema::VERSION) . ' WHERE id_version = ' . $idVersion . ' AND id_block = ' . $idBlock
        );
        $data = is_string($json) ? json_decode($json, true) : null;

        return is_array($data) ? $data : null;
    }
}
