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

use Configuration;
use Db;
use Quince\Block\Front\BlockCache;
use Quince\Block\Rule\RuleTypes;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Odczyt bloków na froncie.
 *
 * Wszystko, co nie zależy od języka i od chwili (aktywne bloki sklepu,
 * harmonogram, reguły, przypisania do hooków), trzyma indeks sklepu zapisany
 * w `BlockCache`. Strona bez bloków w danym hooku nie pyta więc bazy wcale.
 * Treść idzie osobnym zapytaniem, tylko dla bloków, które przeszły reguły,
 * i jest pamiętana do końca żądania.
 */
final class BlockReader
{
    /** @var array<int,array<string,mixed>> indeksy sklepów w tym żądaniu */
    private static array $indexes = [];

    /** @var array<string,array<string,string>|null> treści w tym żądaniu, klucz: sklep-język-blok */
    private static array $contents = [];

    /** @var array<string,array<string,string>|null> kod head/stopki w tym żądaniu */
    private static array $codes = [];

    public static function reset(): void
    {
        self::$indexes = [];
        self::$contents = [];
        self::$codes = [];
    }

    /**
     * Indeks sklepu.
     *
     * @return array{blocks:array<int,array<string,mixed>>,hooks:array<string,array<int,int>>,js:bool,utm:bool}
     */
    public function index(int $idShop): array
    {
        if (isset(self::$indexes[$idShop])) {
            return self::$indexes[$idShop];
        }

        $cached = BlockCache::get('index_' . $idShop);

        if (!is_array($cached) || !isset($cached['blocks'], $cached['hooks'])) {
            $cached = $this->buildIndex($idShop);
            BlockCache::set('index_' . $idShop, $cached);
        }

        // JSON zamienia klucze liczbowe na tekst tylko w zapisie, odczyt
        // z `json_decode(..., true)` oddaje je znów jako liczby.
        return self::$indexes[$idShop] = [
            'blocks' => (array) $cached['blocks'],
            'hooks' => (array) $cached['hooks'],
            'js' => (bool) ($cached['js'] ?? false),
            'utm' => (bool) ($cached['utm'] ?? false),
        ];
    }

    /**
     * Treść bloków (wariant A i B) w języku. Brak wiersza w języku oznacza
     * treść z języka domyślnego, a nie zniknięcie bloku.
     *
     * @param array<int,int> $ids
     *
     * @return array<int,array{content:string,content_b:string}>
     */
    public function contents(array $ids, int $idShop, int $idLang): array
    {
        return $this->fetchLang($ids, $idShop, $idLang, ['content', 'content_b'], self::$contents);
    }

    /**
     * Kod do `<head>` i przed `</body>`.
     *
     * @param array<int,int> $ids
     *
     * @return array<int,array{head_code:string,footer_code:string}>
     */
    public function codes(array $ids, int $idShop, int $idLang): array
    {
        return $this->fetchLang($ids, $idShop, $idLang, ['head_code', 'footer_code'], self::$codes);
    }

    /**
     * Blok do podglądu po prywatnym tokenie, bez względu na aktywność.
     *
     * @return array<string,mixed>|null
     */
    public function byPreviewToken(string $token): ?array
    {
        if (preg_match('/^[A-Za-z0-9_-]{16,64}$/', $token) !== 1) {
            return null;
        }

        $row = Db::getInstance()->getRow(
            'SELECT `id_block`, `title`, `preview_token`
               FROM `' . _DB_PREFIX_ . Schema::BLOCK . '`
              WHERE `preview_token` = \'' . pSQL($token) . '\''
        );

        if (!is_array($row) || !hash_equals((string) $row['preview_token'], $token)) {
            return null;
        }

        return ['id_block' => (int) $row['id_block'], 'title' => (string) $row['title']];
    }

    /**
     * Treść do podglądu: najpierw bieżący sklep, potem dowolny sklep z treścią.
     *
     * @return array{content:string,content_b:string,head_code:string,footer_code:string}
     */
    public function previewContent(int $idBlock, int $idShop, int $idLang): array
    {
        $defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $rows = Db::getInstance()->executeS(
            'SELECT `id_shop`, `id_lang`, `content`, `content_b`, `head_code`, `footer_code`
               FROM `' . _DB_PREFIX_ . Schema::BLOCK_LANG . '`
              WHERE `id_block` = ' . (int) $idBlock . '
                AND `id_lang` IN (' . (int) $idLang . ', ' . $defaultLang . ')'
        ) ?: [];

        $best = null;
        $bestScore = -1;

        foreach ($rows as $row) {
            $score = ((int) $row['id_shop'] === $idShop ? 2 : 0) + ((int) $row['id_lang'] === $idLang ? 1 : 0);

            if ($score > $bestScore) {
                $best = $row;
                $bestScore = $score;
            }
        }

        return [
            'content' => (string) ($best['content'] ?? ''),
            'content_b' => (string) ($best['content_b'] ?? ''),
            'head_code' => (string) ($best['head_code'] ?? ''),
            'footer_code' => (string) ($best['footer_code'] ?? ''),
        ];
    }

    /**
     * Sumy wyświetleń i kliknięć wariantów, jednym zapytaniem dla wielu bloków.
     *
     * @param array<int,int> $ids
     *
     * @return array<int,array<string,array{views:int,clicks:int}>>
     */
    public function abTotals(array $ids, int $idShop): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        if ($ids === []) {
            return [];
        }

        $rows = Db::getInstance()->executeS(
            'SELECT `id_block`, `variant`, SUM(`views`) AS `views`, SUM(`clicks`) AS `clicks`
               FROM `' . _DB_PREFIX_ . Schema::STATS . '`
              WHERE `id_shop` = ' . (int) $idShop . '
                AND `id_block` IN (' . implode(',', $ids) . ')
              GROUP BY `id_block`, `variant`'
        ) ?: [];

        $out = [];

        foreach ($rows as $row) {
            $variant = $row['variant'] === 'b' ? 'b' : 'a';
            $out[(int) $row['id_block']][$variant] = [
                'views' => (int) $row['views'],
                'clicks' => (int) $row['clicks'],
            ];
        }

        return $out;
    }

    /**
     * @param array<int,int> $ids
     * @param array<int,string> $columns
     * @param array<string,array<string,string>|null> $memo
     *
     * @return array<int,array<string,string>>
     */
    private function fetchLang(array $ids, int $idShop, int $idLang, array $columns, array &$memo): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $missing = [];

        foreach ($ids as $id) {
            if (!array_key_exists($idShop . '-' . $idLang . '-' . $id, $memo)) {
                $missing[] = $id;
            }
        }

        if ($missing !== []) {
            $defaultLang = (int) Configuration::get('PS_LANG_DEFAULT');
            $select = implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $columns));
            $rows = Db::getInstance()->executeS(
                'SELECT `id_block`, `id_lang`, ' . $select . '
                   FROM `' . _DB_PREFIX_ . Schema::BLOCK_LANG . '`
                  WHERE `id_shop` = ' . (int) $idShop . '
                    AND `id_block` IN (' . implode(',', $missing) . ')
                    AND `id_lang` IN (' . (int) $idLang . ', ' . $defaultLang . ')'
            ) ?: [];

            $found = [];

            foreach ($rows as $row) {
                $id = (int) $row['id_block'];

                // Wiersz w bieżącym języku wygrywa z wierszem języka domyślnego.
                if (isset($found[$id]) && (int) $row['id_lang'] !== $idLang) {
                    continue;
                }

                $values = [];

                foreach ($columns as $column) {
                    $values[$column] = (string) ($row[$column] ?? '');
                }

                $found[$id] = $values;
            }

            foreach ($missing as $id) {
                $memo[$idShop . '-' . $idLang . '-' . $id] = $found[$id] ?? null;
            }
        }

        $out = [];

        foreach ($ids as $id) {
            $values = $memo[$idShop . '-' . $idLang . '-' . $id] ?? null;

            if ($values !== null) {
                $out[$id] = $values;
            }
        }

        return $out;
    }

    /**
     * Cztery zapytania, wykonywane raz po każdej zmianie w panelu.
     *
     * @return array<string,mixed>
     */
    private function buildIndex(int $idShop): array
    {
        $p = _DB_PREFIX_;
        $db = Db::getInstance();

        $rows = $db->executeS(
            'SELECT b.`id_block`, b.`rule_logic`, b.`date_from`, b.`date_to`, b.`days`,
                    b.`time_from`, b.`time_to`, b.`auto_refresh`, b.`lazy_load`,
                    b.`ab_enabled`, b.`ab_auto_optimize`, b.`ab_min_views`
               FROM `' . $p . Schema::BLOCK . '` b
              INNER JOIN `' . $p . Schema::BLOCK_SHOP . '` bs
                 ON bs.`id_block` = b.`id_block` AND bs.`id_shop` = ' . (int) $idShop . '
              WHERE b.`active` = 1
              ORDER BY b.`position` ASC, b.`id_block` ASC'
        ) ?: [];

        $blocks = [];
        $js = false;

        foreach ($rows as $row) {
            $id = (int) $row['id_block'];
            $days = array_values(array_filter(
                array_map('intval', explode(',', (string) $row['days'])),
                static fn (int $d): bool => $d >= 1 && $d <= 7
            ));

            $blocks[$id] = [
                'id' => $id,
                'rule_logic' => $row['rule_logic'] === 'or' ? 'or' : 'and',
                'date_from' => self::dateOrNull($row['date_from']),
                'date_to' => self::dateOrNull($row['date_to']),
                'days' => $days,
                'time_from' => self::timeOrNull($row['time_from']),
                'time_to' => self::timeOrNull($row['time_to']),
                'auto_refresh' => (bool) $row['auto_refresh'],
                'lazy_load' => (bool) $row['lazy_load'],
                'ab_enabled' => (bool) $row['ab_enabled'],
                'ab_auto_optimize' => (bool) $row['ab_auto_optimize'],
                'ab_min_views' => max(1, (int) $row['ab_min_views']),
                'code' => false,
                'rules' => [],
            ];

            $js = $js || $row['auto_refresh'] || $row['lazy_load'] || $row['ab_enabled'];
        }

        $hooks = [];
        $utm = false;

        if ($blocks !== []) {
            $in = implode(',', array_keys($blocks));

            $hookRows = $db->executeS(
                'SELECT h.`name`, bh.`id_block`
                   FROM `' . $p . Schema::BLOCK_HOOK . '` bh
                  INNER JOIN `' . $p . Schema::HOOK . '` h ON h.`id_hook` = bh.`id_hook`
                  WHERE h.`active` = 1 AND bh.`id_block` IN (' . $in . ')'
            ) ?: [];

            $byBlock = [];

            foreach ($hookRows as $row) {
                $byBlock[(int) $row['id_block']][] = strtolower((string) $row['name']);
            }

            // Kolejność w hooku = kolejność bloków (pozycja, potem id).
            foreach (array_keys($blocks) as $id) {
                foreach ($byBlock[$id] ?? [] as $name) {
                    $hooks[$name][] = $id;
                }
            }

            $ruleRows = $db->executeS(
                'SELECT `id_block`, `type`, `mode`, `value`
                   FROM `' . $p . Schema::RULE . '`
                  WHERE `id_block` IN (' . $in . ')
                  ORDER BY `id_rule` ASC'
            ) ?: [];

            foreach ($ruleRows as $row) {
                $type = (string) $row['type'];
                $value = RuleTypes::normalize($type, json_decode((string) $row['value'], true));

                // Reguła nieznanego typu albo z zepsutą wartością nie może po
                // cichu zniknąć: przy „i" pokazałaby blok wszędzie. Zostaje jako
                // reguła, której nic nie spełnia.
                $blocks[(int) $row['id_block']]['rules'][] = [
                    'type' => $value === null ? '' : $type,
                    'mode' => $row['mode'] === 'exclude' ? 'exclude' : 'include',
                    'value' => $value ?? [],
                ];

                $utm = $utm || $type === 'utm';
            }

            $codeRows = $db->executeS(
                'SELECT DISTINCT `id_block`
                   FROM `' . $p . Schema::BLOCK_LANG . '`
                  WHERE `id_shop` = ' . (int) $idShop . '
                    AND `id_block` IN (' . $in . ')
                    AND ((`head_code` IS NOT NULL AND `head_code` <> \'\')
                      OR (`footer_code` IS NOT NULL AND `footer_code` <> \'\'))'
            ) ?: [];

            foreach ($codeRows as $row) {
                $blocks[(int) $row['id_block']]['code'] = true;
            }
        }

        return ['blocks' => $blocks, 'hooks' => $hooks, 'js' => $js || $utm, 'utm' => $utm];
    }

    private static function dateOrNull($value): ?string
    {
        $value = (string) $value;

        return ($value === '' || strpos($value, '0000-00-00') === 0) ? null : $value;
    }

    private static function timeOrNull($value): ?string
    {
        $value = (string) $value;

        if ($value === '') {
            return null;
        }

        // TIME z bazy przychodzi jako HH:MM:SS; porównujemy tekstowo w tym formacie.
        return strlen($value) === 5 ? $value . ':00' : substr($value, 0, 8);
    }
}
