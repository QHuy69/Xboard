<?php

namespace App\Services;

use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class TelegramDailyBusinessReportService
{
    public const TIMEZONE = 'Asia/Ho_Chi_Minh';
    private const TOP_TRAFFIC_LIMIT = 10;
    private const TOP_COUPON_LIMIT = 5;

    /**
     * Build one immutable commercial summary for a completed Vietnam calendar
     * day. Money remains in Xboard's integer minor unit and traffic in bytes.
     *
     * @return array<string, mixed>
     */
    public function summarize(string $date): array
    {
        [$startAt, $endAt] = $this->dayBounds($date);
        $traffic = $this->trafficSummary($startAt, $endAt);

        return [
            'date' => $date,
            'timezone' => self::TIMEZONE,
            'traffic' => $traffic,
            'top_servers' => $this->topServers($startAt, $endAt),
            'top_users' => $this->topUsers($startAt, $endAt),
            'sales' => $this->salesSummary($startAt, $endAt),
            'top_coupons' => $this->topCoupons($startAt, $endAt),
        ];
    }

    /** @return array{0: int, 1: int} */
    private function dayBounds(string $date): array
    {
        try {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, self::TIMEZONE);
        } catch (Throwable) {
            $day = null;
        }

        if (!$day || $day->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Daily report date must use YYYY-MM-DD.');
        }

        return [$day->startOfDay()->timestamp, $day->addDay()->startOfDay()->timestamp];
    }

    /**
     * paid_at is the accounting boundary. STATUS_DISCOUNTED is a formerly
     * completed order later consumed as upgrade credit, not a cancellation.
     */
    private function paidOrders(int $startAt, int $endAt)
    {
        return DB::table('v2_order')
            ->where('paid_at', '>=', $startAt)
            ->where('paid_at', '<', $endAt)
            ->whereIn('status', [
                Order::STATUS_PROCESSING,
                Order::STATUS_COMPLETED,
                Order::STATUS_DISCOUNTED,
            ]);
    }

    /** @return array<string, int> */
    private function salesSummary(int $startAt, int $endAt): array
    {
        $summary = $this->paidOrders($startAt, $endAt)
            ->selectRaw(
                'COUNT(*) AS order_count,
                COUNT(DISTINCT user_id) AS buyer_count,
                COALESCE(SUM(total_amount), 0) AS revenue,
                COALESCE(SUM(COALESCE(balance_amount, 0)), 0) AS balance_used,
                COALESCE(SUM(COALESCE(handling_amount, 0)), 0) AS handling_fees,
                COALESCE(SUM(CASE WHEN type = ? THEN 1 ELSE 0 END), 0) AS new_purchase,
                COALESCE(SUM(CASE WHEN type = ? THEN 1 ELSE 0 END), 0) AS renewal,
                COALESCE(SUM(CASE WHEN type = ? THEN 1 ELSE 0 END), 0) AS upgrade,
                COALESCE(SUM(CASE WHEN type = ? THEN 1 ELSE 0 END), 0) AS traffic_reset,
                COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS processing_count,
                COALESCE(SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END), 0) AS activated_count,
                COALESCE(SUM(total_amount + COALESCE(handling_amount, 0)), 0) AS recorded_total,
                COALESCE(SUM(total_amount + COALESCE(balance_amount, 0)), 0) AS service_value,
                COALESCE(SUM(COALESCE(discount_amount, 0)), 0) AS discounts',
                [
                    Order::TYPE_NEW_PURCHASE,
                    Order::TYPE_RENEWAL,
                    Order::TYPE_UPGRADE,
                    Order::TYPE_RESET_TRAFFIC,
                    Order::STATUS_PROCESSING,
                    Order::STATUS_COMPLETED,
                    Order::STATUS_DISCOUNTED,
                ]
            )
            ->first();

        $keys = [
            'order_count',
            'buyer_count',
            'revenue',
            'balance_used',
            'handling_fees',
            'new_purchase',
            'renewal',
            'upgrade',
            'traffic_reset',
            'processing_count',
            'activated_count',
            'recorded_total',
            'service_value',
            'discounts',
        ];

        $result = [];
        foreach ($keys as $key) {
            $result[$key] = (int) ($summary->{$key} ?? 0);
        }

        return $result;
    }

    /**
     * Coupon inventory is reserved at order creation and is not restored on
     * cancellation. Ranking paid orders is the safe successful-use metric.
     *
     * @return list<array{id: int, code: string, uses: int, unique_buyers: int, discount_amount: int}>
     */
    private function topCoupons(int $startAt, int $endAt): array
    {
        return $this->paidOrders($startAt, $endAt)
            ->from('v2_order as o')
            ->leftJoin('v2_coupon as c', 'c.id', '=', 'o.coupon_id')
            ->whereNotNull('o.coupon_id')
            ->select(['o.coupon_id as id', 'c.code'])
            ->selectRaw(
                'COUNT(o.id) AS uses,
                COUNT(DISTINCT o.user_id) AS unique_buyers,
                COALESCE(SUM(COALESCE(o.discount_amount, 0)), 0) AS discount_amount'
            )
            ->groupBy('o.coupon_id', 'c.code')
            ->orderByDesc('uses')
            ->orderBy('o.coupon_id')
            ->limit(self::TOP_COUPON_LIMIT)
            ->get()
            ->map(static fn ($coupon): array => [
                'id' => (int) $coupon->id,
                'code' => $coupon->code !== null
                    ? (string) $coupon->code
                    : 'Coupon #' . (int) $coupon->id,
                'uses' => (int) $coupon->uses,
                'unique_buyers' => (int) $coupon->unique_buyers,
                'discount_amount' => (int) $coupon->discount_amount,
            ])
            ->all();
    }

    /**
     * @return array{upload: int, download: int, total: int, server_row_count: int, user_row_count: int, has_data: bool, last_updated_at: int|null, is_stale: bool}
     */
    private function trafficSummary(int $startAt, int $endAt): array
    {
        $server = DB::table('v2_stat_server')
            ->where('record_type', 'd')
            ->where('record_at', '>=', $startAt)
            ->where('record_at', '<', $endAt)
            ->selectRaw(
                'COUNT(*) AS row_count,
                COALESCE(SUM(u), 0) AS upload,
                COALESCE(SUM(d), 0) AS download,
                MAX(updated_at) AS last_updated_at'
            )
            ->first();

        $userRowCount = DB::table('v2_stat_user')
            ->where('record_type', 'd')
            ->where('record_at', '>=', $startAt)
            ->where('record_at', '<', $endAt)
            ->count();

        $serverRowCount = (int) ($server->row_count ?? 0);
        $upload = (int) ($server->upload ?? 0);
        $download = (int) ($server->download ?? 0);
        $lastUpdatedAt = isset($server->last_updated_at)
            ? (int) $server->last_updated_at
            : null;
        $hasData = $serverRowCount > 0;
        if (!$hasData) {
            $latestKnown = DB::table('v2_stat_server')
                ->where('record_type', 'd')
                ->max('updated_at');
            $lastUpdatedAt = $latestKnown !== null ? (int) $latestKnown : null;
        }

        return [
            'upload' => $upload,
            'download' => $download,
            'total' => $upload + $download,
            'server_row_count' => $serverRowCount,
            'user_row_count' => (int) $userRowCount,
            'has_data' => $hasData,
            'last_updated_at' => $lastUpdatedAt,
            'is_stale' => !$hasData || $lastUpdatedAt === null || $lastUpdatedAt < $endAt,
        ];
    }

    /** @return list<array{id: int, name: string, type: string, upload: int, download: int, total: int}> */
    private function topServers(int $startAt, int $endAt): array
    {
        return DB::table('v2_stat_server as s')
            ->leftJoin('v2_server as n', 'n.id', '=', 's.server_id')
            ->where('s.record_type', 'd')
            ->where('s.record_at', '>=', $startAt)
            ->where('s.record_at', '<', $endAt)
            ->select(['s.server_id as id', 's.server_type as type', 'n.name'])
            ->selectRaw(
                'COALESCE(SUM(s.u), 0) AS upload,
                COALESCE(SUM(s.d), 0) AS download,
                COALESCE(SUM(s.u), 0) + COALESCE(SUM(s.d), 0) AS total'
            )
            ->groupBy('s.server_id', 's.server_type', 'n.name')
            ->orderByDesc('total')
            ->orderBy('s.server_id')
            ->orderBy('s.server_type')
            ->limit(self::TOP_TRAFFIC_LIMIT)
            ->get()
            ->map(static fn ($server): array => [
                'id' => (int) $server->id,
                'name' => $server->name !== null
                    ? (string) $server->name
                    : 'Node #' . (int) $server->id,
                'type' => strtoupper((string) $server->type),
                'upload' => (int) $server->upload,
                'download' => (int) $server->download,
                'total' => (int) $server->total,
            ])
            ->all();
    }

    /** @return list<array{id: int, email: string, upload: int, download: int, total: int}> */
    private function topUsers(int $startAt, int $endAt): array
    {
        return DB::table('v2_stat_user as s')
            ->leftJoin('v2_user as u', 'u.id', '=', 's.user_id')
            ->where('s.record_type', 'd')
            ->where('s.record_at', '>=', $startAt)
            ->where('s.record_at', '<', $endAt)
            ->select(['s.user_id as id', 'u.email'])
            ->selectRaw(
                'COALESCE(SUM(s.u), 0) AS upload,
                COALESCE(SUM(s.d), 0) AS download,
                COALESCE(SUM(s.u), 0) + COALESCE(SUM(s.d), 0) AS total'
            )
            ->groupBy('s.user_id', 'u.email')
            ->orderByDesc('total')
            ->orderBy('s.user_id')
            ->limit(self::TOP_TRAFFIC_LIMIT)
            ->get()
            ->map(static fn ($user): array => [
                'id' => (int) $user->id,
                'email' => $user->email !== null
                    ? (string) $user->email
                    : 'User #' . (int) $user->id,
                'upload' => (int) $user->upload,
                'download' => (int) $user->download,
                'total' => (int) $user->total,
            ])
            ->all();
    }
}
