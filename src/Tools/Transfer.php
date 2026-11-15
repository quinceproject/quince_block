<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Block\Tools;

use Db;
use Quince\Block\Storage\BlockRepository;
use Quince\Block\Storage\HookRepository;
use Quince\Block\Storage\RuleRepository;
use Quince\Block\Storage\Schema;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Eksport i import bloków jako JSON.
 *
 * Plik niesie hooki (po nazwie), bloki z treściami (sklep po numerze, język po
 * kodzie ISO), przypisaniami do sklepów i hooków oraz reguły. Statystyk i historii
 * nie przenosi. Import sprawdza cały plik przed zapisem i zapisuje go w jednej
 * transakcji: błąd w połowie nie zostawia częściowych danych.
 */
final class Transfer
{
    public const FORMAT = 'quince_block';
    public const VERSION = 1;

    public function __construct(
        private readonly BlockRepository $blocks,
        private readonly HookRepository $hooks,
        private readonly RuleRepository $rules,
        private readonly RuleNormalizer $normalizer,
    ) {
    }

    /** @return array<string,mixed> */
    public function export(): array
    {
        $isoById = [];

        foreach (\Language::getLanguages(false) as $language) {
            $isoById[(int) $language['id_lang']] = (string) $language['iso_code'];
        }

        $hooks = $this->hooks->all();
        $hookNames = array_column($hooks, 'name', 'id');
        $blocks = [];

        foreach ($this->blocks->choices() as $choice) {
            $id = $choice['id'];
            $row = $this->blocks->find($id);

            if ($row === null) {
                continue;
            }

            $contents = [];

            foreach ($this->blocks->contents($id) as $idShop => $langs) {
                foreach ($langs as $idLang => $fields) {
                    $contents[] = ['id_shop' => $idShop, 'lang' => $isoById[$idLang] ?? (string) $idLang] + $fields;
                }
            }

            $blocks[] = array_intersect_key($row, array_flip(BlockRepository::ROW_FIELDS)) + [
                'shops' => $this->blocks->shopIds($id),
                'hooks' => array_values(array_filter(array_map(static fn (int $h): string => (string) ($hookNames[$h] ?? ''), $this->blocks->hookIds($id)))),
                'contents' => $contents,
                'rules' => array_map(static fn (array $r): array => [
                    'type' => $r['type'],
                    'mode' => $r['mode'],
                    'value' => $r['value'],
                ], $this->rules->forBlock($id)),
            ];
        }

        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'exported_at' => date('c'),
            'hooks' => array_map(static fn (array $h): array => [
                'name' => $h['name'],
                'description' => $h['description'],
                'active' => $h['active'],
            ], $hooks),
            'blocks' => $blocks,
        ];
    }

    /**
     * @param array<string,mixed> $data odczytany plik
     * @param bool $replace true: usuń wszystkie obecne bloki przed importem
     *
     * @return array{blocks:int,hooks:string[],skipped_shops:int[]} nazwy hooków do rejestracji modułu
     *
     * @throws \InvalidArgumentException opis błędu w pliku (po angielsku)
     */
    public function import(array $data, bool $replace): array
    {
        [$hooks, $blocks, $skippedShops] = $this->validate($data);
        $created = 0;
        $db = Db::getInstance();
        $db->execute('START TRANSACTION');

        try {
            if ($replace) {
                $ids = array_column($this->blocks->choices(), 'id');

                if ($ids !== []) {
                    foreach ([Schema::BLOCK_SHOP, Schema::BLOCK_LANG, Schema::BLOCK_HOOK, Schema::RULE, Schema::STATS, Schema::VERSION, Schema::BLOCK] as $table) {
                        $this->run('DELETE FROM `' . _DB_PREFIX_ . $table . '` WHERE id_block IN (' . implode(',', array_map('intval', $ids)) . ')');
                    }
                }
            }

            $hookIds = [];

            foreach ($hooks as $hook) {
                $existing = $this->hooks->findByName($hook['name']);
                $hookIds[$hook['name']] = $existing !== null
                    ? $existing['id']
                    : $this->hooks->create($hook['name'], $hook['description'], $hook['active']);
            }

            foreach ($blocks as $block) {
                $id = $this->blocks->writeRow(0, $block['row']);

                foreach ($block['shops'] as $idShop) {
                    $this->run('INSERT IGNORE INTO `' . _DB_PREFIX_ . Schema::BLOCK_SHOP . '` (id_block, id_shop) VALUES (' . $id . ', ' . (int) $idShop . ')');
                }

                foreach ($block['hooks'] as $name) {
                    if (isset($hookIds[$name])) {
                        $this->run('INSERT IGNORE INTO `' . _DB_PREFIX_ . Schema::BLOCK_HOOK . '` (id_block, id_hook) VALUES (' . $id . ', ' . (int) $hookIds[$name] . ')');
                    }
                }

                foreach ($block['contents'] as $content) {
                    $values = [];

                    foreach (BlockRepository::CONTENT_FIELDS as $field) {
                        $values[] = "'" . pSQL((string) $content[$field], true) . "'";
                    }

                    $this->run(
                        'REPLACE INTO `' . _DB_PREFIX_ . Schema::BLOCK_LANG . '` (id_block, id_shop, id_lang, `'
                        . implode('`, `', BlockRepository::CONTENT_FIELDS) . '`) VALUES ('
                        . $id . ', ' . (int) $content['id_shop'] . ', ' . (int) $content['id_lang'] . ', ' . implode(', ', $values) . ')'
                    );
                }

                foreach ($block['rules'] as $rule) {
                    $this->rules->save($id, 0, $rule['type'], $rule['mode'], $rule['value']);
                }

                ++$created;
            }

            $db->execute('COMMIT');
        } catch (\Throwable $e) {
            $db->execute('ROLLBACK');

            throw $e;
        }

        return ['blocks' => $created, 'hooks' => array_keys($hookIds), 'skipped_shops' => $skippedShops];
    }

    /**
     * Sprawdza cały plik i sprowadza go do kształtu zapisu. Nic nie zapisuje.
     *
     * @param array<string,mixed> $data
     *
     * @return array{0:array<int,array{name:string,description:string,active:bool}>,1:array<int,array<string,mixed>>,2:int[]}
     */
    private function validate(array $data): array
    {
        if (($data['format'] ?? '') !== self::FORMAT || (int) ($data['version'] ?? 0) !== self::VERSION) {
            throw new \InvalidArgumentException('This is not a Quince Block export file.');
        }

        if (!is_array($data['blocks'] ?? null) || !is_array($data['hooks'] ?? [])) {
            throw new \InvalidArgumentException('The file has no list of blocks.');
        }

        $shopIds = array_map('intval', \Shop::getShops(false, null, true));
        $langByIso = [];

        foreach (\Language::getLanguages(false) as $language) {
            $langByIso[mb_strtolower((string) $language['iso_code'])] = (int) $language['id_lang'];
        }

        $hooks = [];

        foreach ((array) ($data['hooks'] ?? []) as $n => $hook) {
            $name = is_array($hook) ? (string) ($hook['name'] ?? '') : '';
            $this->assertHookName($name, 'Hook #' . ((int) $n + 1));
            $hooks[$name] = [
                'name' => $name,
                'description' => mb_substr((string) ($hook['description'] ?? ''), 0, 255),
                'active' => !isset($hook['active']) || (bool) $hook['active'],
            ];
        }

        $blocks = [];
        $skippedShops = [];

        foreach ($data['blocks'] as $n => $block) {
            $where = 'Block #' . ((int) $n + 1);

            if (!is_array($block)) {
                throw new \InvalidArgumentException($where . ': not a block.');
            }

            $row = [
                'title' => mb_substr((string) ($block['title'] ?? ''), 0, 255),
                'active' => !empty($block['active']) ? 1 : 0,
                'position' => max(0, (int) ($block['position'] ?? 0)),
                'rule_logic' => ($block['rule_logic'] ?? '') === 'or' ? 'or' : 'and',
                'date_from' => $this->dateTime($block['date_from'] ?? null, $where),
                'date_to' => $this->dateTime($block['date_to'] ?? null, $where),
                'days' => $this->days((string) ($block['days'] ?? ''), $where),
                'time_from' => $this->time($block['time_from'] ?? null, $where),
                'time_to' => $this->time($block['time_to'] ?? null, $where),
                'auto_refresh' => !empty($block['auto_refresh']) ? 1 : 0,
                'lazy_load' => !empty($block['lazy_load']) ? 1 : 0,
                'ab_enabled' => !empty($block['ab_enabled']) ? 1 : 0,
                'ab_auto_optimize' => !empty($block['ab_auto_optimize']) ? 1 : 0,
                'ab_min_views' => max(100, (int) ($block['ab_min_views'] ?? 100)),
            ];

            $shops = [];

            foreach ((array) ($block['shops'] ?? []) as $idShop) {
                if (in_array((int) $idShop, $shopIds, true)) {
                    $shops[] = (int) $idShop;
                } else {
                    $skippedShops[(int) $idShop] = (int) $idShop;
                }
            }

            $blockHooks = [];

            foreach ((array) ($block['hooks'] ?? []) as $name) {
                $name = (string) $name;
                $this->assertHookName($name, $where);

                // Hook użyty w bloku, a nieopisany w sekcji hooków, też powstaje.
                $hooks[$name] ??= ['name' => $name, 'description' => '', 'active' => true];
                $blockHooks[] = $name;
            }

            $contents = [];

            foreach ((array) ($block['contents'] ?? []) as $content) {
                if (!is_array($content)) {
                    throw new \InvalidArgumentException($where . ': wrong content entry.');
                }

                $idShop = (int) ($content['id_shop'] ?? 0);
                $iso = mb_strtolower((string) ($content['lang'] ?? ''));

                if (!in_array($idShop, $shopIds, true)) {
                    $skippedShops[$idShop] = $idShop;

                    continue;
                }

                if (!isset($langByIso[$iso])) {
                    // Język, którego sklep nie ma, pomijamy: treść nie miałaby gdzie się pokazać.
                    continue;
                }

                $entry = ['id_shop' => $idShop, 'id_lang' => $langByIso[$iso]];

                foreach (BlockRepository::CONTENT_FIELDS as $field) {
                    $value = $content[$field] ?? '';

                    if (!is_string($value)) {
                        throw new \InvalidArgumentException($where . ': content must be text.');
                    }

                    $entry[$field] = $value;
                }

                $contents[] = $entry;
            }

            $rules = [];

            foreach ((array) ($block['rules'] ?? []) as $r => $rule) {
                $type = is_array($rule) ? (string) ($rule['type'] ?? '') : '';

                if (!$this->normalizer->isKnown($type)) {
                    throw new \InvalidArgumentException($where . ', rule #' . ((int) $r + 1) . ': unknown rule type "' . $type . '".');
                }

                [$value, $error] = $this->normalizer->normalize($type, is_array($rule['value'] ?? null) ? $rule['value'] : []);

                if ($value === null) {
                    throw new \InvalidArgumentException($where . ', rule #' . ((int) $r + 1) . ': ' . $error);
                }

                $rules[] = [
                    'type' => $type,
                    'mode' => ($rule['mode'] ?? '') === 'exclude' ? 'exclude' : 'include',
                    'value' => $value,
                ];
            }

            $blocks[] = ['row' => $row, 'shops' => $shops, 'hooks' => $blockHooks, 'contents' => $contents, 'rules' => $rules];
        }

        return [array_values($hooks), $blocks, array_values($skippedShops)];
    }

    private function assertHookName(string $name, string $where): void
    {
        $suffix = substr($name, strlen(Schema::HOOK_PREFIX));

        if (!str_starts_with($name, Schema::HOOK_PREFIX) || !preg_match(HookRepository::SUFFIX_PATTERN, $suffix)) {
            throw new \InvalidArgumentException($where . ': wrong hook name "' . $name . '".');
        }
    }

    private function dateTime(mixed $value, string $where): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = (string) $value;

        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value) || strtotime($value) === false) {
            throw new \InvalidArgumentException($where . ': wrong date "' . $value . '".');
        }

        return $value;
    }

    private function time(mixed $value, string $where): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = (string) $value;

        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $value)) {
            throw new \InvalidArgumentException($where . ': wrong time "' . $value . '".');
        }

        return strlen($value) === 5 ? $value . ':00' : $value;
    }

    private function days(string $value, string $where): string
    {
        if (trim($value) === '') {
            return '';
        }

        $days = array_map('intval', explode(',', $value));

        foreach ($days as $day) {
            if ($day < 1 || $day > 7) {
                throw new \InvalidArgumentException($where . ': wrong days "' . $value . '".');
            }
        }

        $days = array_values(array_unique($days));
        sort($days);

        return count($days) === 7 ? '' : implode(',', $days);
    }

    private function run(string $sql): void
    {
        if (!Db::getInstance()->execute($sql)) {
            throw new \RuntimeException('Database error: ' . Db::getInstance()->getMsgError());
        }
    }
}
