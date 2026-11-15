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

use Db;
use Quince\Block\Rule\Schedule;
use Quince\Block\Storage\BlockReader;
use Quince\Block\Storage\Schema;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Test A/B: wybór wariantu, zwycięzca, zapis statystyk.
 *
 * Zwycięzca nie jest zapisywany w bazie. Liczymy go ze statystyk (wyższy
 * odsetek kliknięć, gdy oba warianty mają minimum wyświetleń; remis wygrywa A)
 * i pamiętamy w `BlockCache` na kilka minut. Po rozstrzygnięciu blok przestaje
 * zbierać statystyki, więc wynik już się nie zmienia, dopóki panel nie
 * wyzeruje statystyk.
 */
final class AbTest
{
    /** Jak długo pamiętamy wyliczonych zwycięzców, w sekundach. */
    private const WINNER_TTL = 600;

    /** @var array<int,array<int,string>> zwycięzcy sklepów w tym żądaniu */
    private static array $winners = [];

    /**
     * Wariant do pokazania.
     *
     * @param array<string,mixed> $block wpis bloku z indeksu
     *
     * @return array{variant:string,random:bool,track:bool}
     */
    public static function choose(array $block, bool $hasB, int $idShop): array
    {
        if (empty($block['ab_enabled']) || !$hasB) {
            return ['variant' => 'a', 'random' => false, 'track' => false];
        }

        $id = (int) $block['id'];

        if (!empty($block['ab_auto_optimize'])) {
            $winner = self::winners($idShop)[$id] ?? '';

            if ($winner !== '') {
                return ['variant' => $winner, 'random' => false, 'track' => false];
            }
        }

        // Z modułem zgód, ale bez zgody: pokazujemy wariant A i nie liczymy.
        // Losowanie bez zapamiętania przeskakiwałoby między wariantami
        // przy każdej stronie tej samej osoby.
        if (!Consent::trackingAllowed()) {
            return ['variant' => 'a', 'random' => false, 'track' => false];
        }

        if (Consent::cookiesAllowed()) {
            $stored = (string) ($_COOKIE[Consent::AB_COOKIE_PREFIX . $id] ?? '');

            if ($stored === 'a' || $stored === 'b') {
                return ['variant' => $stored, 'random' => true, 'track' => true];
            }
        }

        return ['variant' => random_int(0, 1) === 1 ? 'b' : 'a', 'random' => true, 'track' => true];
    }

    /**
     * Zwycięzcy bloków z auto-optymalizacją: id bloku => 'a'|'b'.
     * Bloki bez rozstrzygnięcia nie występują w wyniku.
     *
     * @return array<int,string>
     */
    public static function winners(int $idShop): array
    {
        if (isset(self::$winners[$idShop])) {
            return self::$winners[$idShop];
        }

        $cached = BlockCache::get('ab_' . $idShop);

        if (is_array($cached)) {
            return self::$winners[$idShop] = array_map('strval', $cached);
        }

        $reader = new BlockReader();
        $blocks = array_filter(
            $reader->index($idShop)['blocks'],
            static fn (array $b): bool => !empty($b['ab_enabled']) && !empty($b['ab_auto_optimize'])
        );

        $winners = [];

        if ($blocks !== []) {
            $totals = $reader->abTotals(array_keys($blocks), $idShop);

            foreach ($blocks as $id => $block) {
                $winner = self::decide($totals[$id] ?? [], (int) $block['ab_min_views']);

                if ($winner !== null) {
                    $winners[(int) $id] = $winner;
                }
            }
        }

        BlockCache::set('ab_' . $idShop, $winners, self::WINNER_TTL);

        return self::$winners[$idShop] = $winners;
    }

    /**
     * Rozstrzygnięcie na podstawie sum. Publiczne dla statystyk w panelu.
     *
     * @param array<string,array{views:int,clicks:int}> $totals
     */
    public static function decide(array $totals, int $minViews): ?string
    {
        $a = $totals['a'] ?? ['views' => 0, 'clicks' => 0];
        $b = $totals['b'] ?? ['views' => 0, 'clicks' => 0];
        $minViews = max(1, $minViews);

        if ($a['views'] < $minViews || $b['views'] < $minViews) {
            return null;
        }

        $ctrA = $a['clicks'] / $a['views'];
        $ctrB = $b['clicks'] / $b['views'];

        return $ctrB > $ctrA ? 'b' : 'a';
    }

    /** Jak długo token wyświetlenia przyjmujemy. Doba: strona z pamięci podręcznej też się liczy. */
    private const TOKEN_TTL = 86400;

    /**
     * Token jednego wyświetlenia, wstawiany do atrybutu `data-qb-token`.
     *
     * Niesie pokazany wariant, czas wydania i losową wartość, podpisane kluczem
     * sklepu. Serwer bierze wariant z tokenu, nie od przeglądarki, więc nikt nie
     * dopisze kliknięć wariantowi, którego nie widział. Dawny token był stały dla
     * bloku i każdy mógł nim zgłaszać dowolny wariant w dowolnej liczbie.
     */
    public static function token(int $idBlock, int $idShop, string $variant): string
    {
        $tresc = $idShop . '.' . $idBlock . '.' . ($variant === 'b' ? 'b' : 'a') . '.' . time() . '.' . bin2hex(random_bytes(8));

        return $tresc . '.' . PageContext::mac($tresc, 'track');
    }

    /**
     * Wariant i jednorazowa wartość z ważnego tokenu albo null.
     *
     * @return array{variant:string,nonce:string}|null
     */
    public static function readToken(int $idBlock, int $idShop, string $token): ?array
    {
        $czesci = explode('.', $token);

        if (count($czesci) !== 6) {
            return null;
        }

        [$sklep, $blok, $wariant, $czas, $nonce, $podpis] = $czesci;
        $tresc = implode('.', array_slice($czesci, 0, 5));

        if (!hash_equals(PageContext::mac($tresc, 'track'), $podpis)
            || (int) $sklep !== $idShop
            || (int) $blok !== $idBlock
            || !in_array($wariant, ['a', 'b'], true)
            || !ctype_xdigit($nonce)
        ) {
            return null;
        }

        $wiek = time() - (int) $czas;

        // Lekko ujemny wiek to różnica zegarów; większy to podróbka albo pomyłka.
        if ($wiek > self::TOKEN_TTL || $wiek < -300) {
            return null;
        }

        return ['variant' => $wariant, 'nonce' => $nonce];
    }

    /**
     * Przyjęcie zdarzenia z tokenu: wyświetlenie raz na token i odwiedzającego,
     * kliknięcie tylko po takim wyświetleniu i też raz.
     *
     * Odwiedzającego rozróżniamy, bo przy pełnej pamięci podręcznej stron ten sam
     * token dostaje wiele osób, a każda z nich ma się policzyć.
     *
     * @param string $kind 'view'|'click'
     */
    public static function recordEvent(int $idBlock, int $idShop, string $nonce, string $visitor, string $variant, string $kind): bool
    {
        $db = Db::getInstance();
        $tabela = _DB_PREFIX_ . Schema::AB_SEEN;
        $klucz = sha1($idShop . '|' . $idBlock . '|' . $nonce . '|' . $visitor);

        // Sprzątanie przy okazji: wiersze żyją tyle, ile token.
        if (random_int(1, 100) === 1) {
            $db->execute('DELETE FROM `' . $tabela . '` WHERE `date_add` < ' . (time() - self::TOKEN_TTL));
        }

        if ($kind === 'view') {
            $db->execute(
                'INSERT IGNORE INTO `' . $tabela . '` (`seen_key`, `id_block`, `clicked`, `date_add`)
                 VALUES (\'' . pSQL($klucz) . '\', ' . (int) $idBlock . ', 0, ' . time() . ')'
            );
        } else {
            $db->execute(
                'UPDATE `' . $tabela . '` SET `clicked` = 1
                 WHERE `seen_key` = \'' . pSQL($klucz) . '\' AND `clicked` = 0'
            );
        }

        if ((int) $db->Affected_Rows() !== 1) {
            return false;
        }

        return self::record($idBlock, $idShop, $variant, $kind);
    }

    /**
     * Zapis wyświetlenia albo kliknięcia do dziennego agregatu.
     *
     * @param string $kind 'view'|'click'
     */
    public static function record(int $idBlock, int $idShop, string $variant, string $kind): bool
    {
        $column = $kind === 'click' ? 'clicks' : 'views';
        $variant = $variant === 'b' ? 'b' : 'a';
        $day = Schedule::now()->format('Y-m-d');

        return Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . Schema::STATS . '`
                (`id_block`, `id_shop`, `variant`, `day`, `views`, `clicks`)
             VALUES (' . (int) $idBlock . ', ' . (int) $idShop . ', \'' . pSQL($variant) . '\', \'' . pSQL($day) . '\', '
                . ($column === 'views' ? 1 : 0) . ', ' . ($column === 'clicks' ? 1 : 0) . ')
             ON DUPLICATE KEY UPDATE `' . $column . '` = `' . $column . '` + 1'
        );
    }
}
