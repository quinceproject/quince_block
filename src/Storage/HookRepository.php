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
 * Hooki `displayQuinceBlock*` założone w panelu.
 *
 * Rejestrację modułu na hooku robi kontroler (`Module::registerHook`), bo to
 * wymaga instancji modułu. Tu jest tylko tabela modułu.
 */
final class HookRepository extends AbstractRepository
{
    /** Część nazwy po przedrostku: litery, cyfry i podkreślenie. */
    public const SUFFIX_PATTERN = '/^[A-Za-z0-9_]{1,40}$/';

    /**
     * @return array<int,array{id:int,name:string,description:string,active:bool,blocks:int}>
     */
    public function all(): array
    {
        $rows = $this->db()->executeS(
            'SELECT h.*, (SELECT COUNT(*) FROM ' . $this->table(Schema::BLOCK_HOOK) . ' bh WHERE bh.id_hook = h.id_hook) AS blocks'
            . ' FROM ' . $this->table(Schema::HOOK) . ' h ORDER BY h.name'
        ) ?: [];

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id_hook'],
            'name' => (string) $r['name'],
            'description' => (string) $r['description'],
            'active' => (bool) $r['active'],
            'blocks' => (int) $r['blocks'],
        ], $rows);
    }

    /** @return int[] */
    public function ids(): array
    {
        $rows = $this->db()->executeS('SELECT id_hook FROM ' . $this->table(Schema::HOOK)) ?: [];

        return array_map(static fn (array $r): int => (int) $r['id_hook'], $rows);
    }

    /** @return array{id:int,name:string,description:string,active:bool}|null */
    public function find(int $id): ?array
    {
        $row = $this->db()->getRow('SELECT * FROM ' . $this->table(Schema::HOOK) . ' WHERE id_hook = ' . $id);

        return is_array($row) && $row ? $this->map($row) : null;
    }

    /** @return array{id:int,name:string,description:string,active:bool}|null */
    public function findByName(string $name): ?array
    {
        $row = $this->db()->getRow('SELECT * FROM ' . $this->table(Schema::HOOK) . " WHERE name = '" . pSQL($name) . "'");

        return is_array($row) && $row ? $this->map($row) : null;
    }

    public function create(string $name, string $description, bool $active): int
    {
        $this->run(
            'INSERT INTO ' . $this->table(Schema::HOOK) . ' (name, description, active) VALUES ('
            . $this->str($name) . ', ' . "'" . pSQL(mb_substr($description, 0, 255)) . "'" . ', ' . ($active ? 1 : 0) . ')'
        );

        return (int) $this->db()->Insert_ID();
    }

    public function update(int $id, string $description, bool $active): void
    {
        $this->run(
            'UPDATE ' . $this->table(Schema::HOOK) . " SET description = '" . pSQL(mb_substr($description, 0, 255)) . "'"
            . ', active = ' . ($active ? 1 : 0) . ' WHERE id_hook = ' . $id
        );
    }

    public function setActive(int $id, bool $active): void
    {
        $this->run('UPDATE ' . $this->table(Schema::HOOK) . ' SET active = ' . ($active ? 1 : 0) . ' WHERE id_hook = ' . $id);
    }

    /** Usuwa hook razem z przypisaniami bloków. */
    public function delete(int $id): void
    {
        $this->transaction(function () use ($id): void {
            $this->run('DELETE FROM ' . $this->table(Schema::BLOCK_HOOK) . ' WHERE id_hook = ' . $id);
            $this->run('DELETE FROM ' . $this->table(Schema::HOOK) . ' WHERE id_hook = ' . $id);
        });
    }

    /** @param array<string,mixed> $row */
    private function map(array $row): array
    {
        return [
            'id' => (int) $row['id_hook'],
            'name' => (string) $row['name'],
            'description' => (string) $row['description'],
            'active' => (bool) $row['active'],
        ];
    }
}
