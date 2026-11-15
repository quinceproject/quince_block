<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

namespace Quince\Block\Controller\Admin;

use PrestaShopBundle\Security\Attribute\AdminSecurity;
use Quince\Block\Storage\StatsRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Wyświetlenia, kliknięcia i CTR per blok i wariant w wybranym zakresie dni,
 * dla sklepów z kontekstu pracownika.
 */
final class StatsController extends BlockBaseController
{
    public function __construct(private readonly StatsRepository $stats)
    {
    }

    #[AdminSecurity("is_granted('read', request.get('_legacy_controller'))", message: 'You do not have permission to view this.')]
    public function indexAction(Request $request): Response
    {
        $to = $this->date((string) $request->query->get('to', '')) ?? date('Y-m-d');
        $from = $this->date((string) $request->query->get('from', '')) ?? date('Y-m-d', strtotime($to . ' -29 days'));

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $rows = $this->stats->summary($from, $to, $this->contextShopIds());
        $views = array_sum(array_column($rows, 'views'));
        $clicks = array_sum(array_column($rows, 'clicks'));

        return $this->renderPanel('stats/index.html.twig', 'stats', [
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'totals' => [
                'views' => $views,
                'clicks' => $clicks,
                'ctr' => $views > 0 ? round($clicks * 100 / $views, 2) : 0,
            ],
        ]);
    }

    private function date(string $value): ?string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        [$y, $m, $d] = array_map('intval', explode('-', $value));

        return checkdate($m, $d, $y) ? $value : null;
    }
}
