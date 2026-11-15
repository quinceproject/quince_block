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
 * Odczyt statystyk wyświetleń i kliknięć. Zapisuje je front (kontroler `track`),
 * panel tylko czyta i zeruje test A/B.
 */
final class StatsRepository extends AbstractRepository
{
    /**
     * Sumy per blok i wariant w zakresie dni, dla sklepów z kontekstu.
     *
     * @param int[] $shopIds
     *
     * @return array<int,array{id_block:int,title:string,variant:string,views:int,clicks:int,ctr:float}>
     */
    public function summary(string $from, string $to, array $shopIds): array
    {
        $rows = $this->db()->executeS(
            'SELECT s.id_block, s.variant, SUM(s.views) AS views, SUM(s.clicks) AS clicks, b.title'
            . ' FROM ' . $this->table(Schema::STATS) . ' s'
            . ' LEFT JOIN ' . $this->table(Schema::BLOCK) . ' b ON b.id_block = s.id_block'
            . " WHERE s.day BETWEEN '" . pSQL($from) . "' AND '" . pSQL($to) . "'"
            . ' AND s.id_shop IN (' . $this->idList($shopIds) . ')'
            . ' GROUP BY s.id_block, s.variant ORDER BY views DESC, s.id_block, s.variant'
        ) ?: [];

        return array_map(fn (array $r): array => [
            'id_block' => (int) $r['id_block'],
            'title' => (string) ($r['title'] ?? ''),
            'variant' => (string) $r['variant'] === 'b' ? 'b' : 'a',
            'views' => (int) $r['views'],
            'clicks' => (int) $r['clicks'],
            'ctr' => $this->ctr((int) $r['views'], (int) $r['clicks']),
        ], $rows);
    }

    /**
     * Wyniki testu A/B jednego bloku (cały okres).
     *
     * @param int[] $shopIds
     *
     * @return array{a:array{views:int,clicks:int,ctr:float},b:array{views:int,clicks:int,ctr:float}}
     */
    public function abResults(int $idBlock, array $shopIds): array
    {
        $rows = $this->db()->executeS(
            'SELECT variant, SUM(views) AS views, SUM(clicks) AS clicks FROM ' . $this->table(Schema::STATS)
            . ' WHERE id_block = ' . $idBlock . ' AND id_shop IN (' . $this->idList($shopIds) . ') GROUP BY variant'
        ) ?: [];
        $out = [
            'a' => ['views' => 0, 'clicks' => 0, 'ctr' => 0.0],
            'b' => ['views' => 0, 'clicks' => 0, 'ctr' => 0.0],
        ];

        foreach ($rows as $row) {
            $variant = (string) $row['variant'] === 'b' ? 'b' : 'a';
            $out[$variant] = [
                'views' => (int) $row['views'],
                'clicks' => (int) $row['clicks'],
                'ctr' => $this->ctr((int) $row['views'], (int) $row['clicks']),
            ];
        }

        return $out;
    }

    /** @param int[] $shopIds */
    public function reset(int $idBlock, array $shopIds): void
    {
        $this->run(
            'DELETE FROM ' . $this->table(Schema::STATS) . ' WHERE id_block = ' . $idBlock
            . ' AND id_shop IN (' . $this->idList($shopIds) . ')'
        );
    }

    private function ctr(int $views, int $clicks): float
    {
        return $views > 0 ? round($clicks * 100 / $views, 2) : 0.0;
    }
}
