<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\TelegramDailyBusinessReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TelegramDailyBusinessReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private const TIMEZONE = 'Asia/Ho_Chi_Minh';

    public function test_sales_use_the_vietnam_paid_at_half_open_window_and_explicit_totals(): void
    {
        [$startAt, $endAt] = $this->vietnamDay('2026-09-03');
        $couponOne = $this->insertCoupon('TOP01', $startAt);
        $couponTwo = $this->insertCoupon('TOP02', $startAt);

        $this->insertOrder([
            'user_id' => 10,
            'coupon_id' => $couponOne,
            'type' => Order::TYPE_NEW_PURCHASE,
            'status' => Order::STATUS_COMPLETED,
            'paid_at' => $startAt,
            'total_amount' => 1000,
            'balance_amount' => 100,
            'handling_amount' => 10,
            'discount_amount' => 50,
        ]);
        $this->insertOrder([
            'user_id' => 10,
            'coupon_id' => $couponOne,
            'type' => Order::TYPE_RENEWAL,
            'status' => Order::STATUS_PROCESSING,
            'paid_at' => $startAt + 3600,
            'total_amount' => 2000,
            'balance_amount' => 200,
            'handling_amount' => 20,
            'discount_amount' => 60,
        ]);
        $this->insertOrder([
            'user_id' => 20,
            'coupon_id' => $couponTwo,
            'type' => Order::TYPE_UPGRADE,
            'status' => Order::STATUS_DISCOUNTED,
            'paid_at' => $endAt - 1,
            'total_amount' => 3000,
            'balance_amount' => 300,
            'handling_amount' => 30,
            'discount_amount' => 70,
        ]);

        // These rows prove that the report is based on paid_at in a half-open
        // Vietnam-local interval, and that unpaid/cancelled rows are excluded.
        foreach ([
            ['paid_at' => $startAt - 1, 'status' => Order::STATUS_COMPLETED],
            ['paid_at' => $endAt, 'status' => Order::STATUS_COMPLETED],
            ['paid_at' => $startAt + 2, 'status' => Order::STATUS_PENDING],
            ['paid_at' => $startAt + 3, 'status' => Order::STATUS_CANCELLED],
        ] as $excluded) {
            $this->insertOrder(array_merge([
                'user_id' => 99,
                'coupon_id' => $couponOne,
                'type' => Order::TYPE_RESET_TRAFFIC,
                'total_amount' => 99999,
                'balance_amount' => 99999,
                'handling_amount' => 99999,
                'discount_amount' => 99999,
            ], $excluded));
        }

        $summary = app(TelegramDailyBusinessReportService::class)->summarize('2026-09-03');

        $this->assertSame('2026-09-03', $summary['date']);
        $this->assertSame(self::TIMEZONE, $summary['timezone']);
        $this->assertSame([
            'order_count' => 3,
            'buyer_count' => 2,
            'revenue' => 6000,
            'balance_used' => 600,
            'handling_fees' => 60,
            'new_purchase' => 1,
            'renewal' => 1,
            'upgrade' => 1,
            'traffic_reset' => 0,
            'processing_count' => 1,
            'activated_count' => 2,
            'recorded_total' => 6060,
            'service_value' => 6600,
            'discounts' => 180,
        ], $summary['sales']);

        $this->assertSame('TOP01', $summary['top_coupons'][0]['code']);
        $this->assertSame(2, $summary['top_coupons'][0]['uses']);
        $this->assertSame(1, $summary['top_coupons'][0]['unique_buyers']);
        $this->assertSame(110, $summary['top_coupons'][0]['discount_amount']);
    }

    public function test_traffic_uses_daily_rows_in_the_same_vietnam_window_and_stable_rank_ties(): void
    {
        [$startAt, $endAt] = $this->vietnamDay('2026-09-03');

        $this->insertServerStat(9, 'vmess', 100, 200, $startAt, 'd', $startAt + 10);
        $this->insertServerStat(3, 'trojan', 150, 150, $startAt, 'd', $startAt + 20);
        $this->insertServerStat(1, 'vmess', 9999, 9999, $startAt - 1, 'd', $startAt);
        $this->insertServerStat(2, 'vmess', 9999, 9999, $endAt, 'd', $endAt);
        $this->insertServerStat(4, 'vmess', 9999, 9999, $startAt, 'm', $startAt);

        $this->insertUserStat(2, 1.0, 250, 250, $startAt, 'd', $startAt + 30);
        $this->insertUserStat(1, 1.0, 200, 300, $startAt, 'd', $startAt + 40);
        $this->insertUserStat(7, 1.0, 9999, 9999, $endAt, 'd', $endAt);
        $this->insertUserStat(8, 1.0, 9999, 9999, $startAt, 'm', $startAt);

        $summary = app(TelegramDailyBusinessReportService::class)->summarize('2026-09-03');

        // Server traffic is raw network usage. It must not be replaced by the
        // rate-weighted user total even when both datasets are available.
        $this->assertSame(250, $summary['traffic']['upload']);
        $this->assertSame(350, $summary['traffic']['download']);
        $this->assertSame(600, $summary['traffic']['total']);
        $this->assertSame(2, $summary['traffic']['server_row_count']);
        $this->assertSame(2, $summary['traffic']['user_row_count']);
        $this->assertTrue($summary['traffic']['has_data']);
        $this->assertSame($startAt + 20, $summary['traffic']['last_updated_at']);
        $this->assertTrue($summary['traffic']['is_stale']);

        $this->assertSame([3, 9], array_column($summary['top_servers'], 'id'));
        $this->assertSame([1, 2], array_column($summary['top_users'], 'id'));
        $this->assertSame([500, 500], array_column($summary['top_users'], 'total'));
    }

    public function test_missing_day_is_not_reported_as_measured_zero_and_shows_latest_telemetry(): void
    {
        [$previousStart] = $this->vietnamDay('2026-09-02');
        $this->insertServerStat(1, 'vmess', 10, 20, $previousStart, 'd', $previousStart + 60);

        $summary = app(TelegramDailyBusinessReportService::class)->summarize('2026-09-03');

        $this->assertFalse($summary['traffic']['has_data']);
        $this->assertTrue($summary['traffic']['is_stale']);
        $this->assertSame(0, $summary['traffic']['server_row_count']);
        $this->assertSame($previousStart + 60, $summary['traffic']['last_updated_at']);
        $this->assertSame([], $summary['top_servers']);
    }

    /** @return array{0: int, 1: int} */
    private function vietnamDay(string $date): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $date, self::TIMEZONE);

        return [$start->timestamp, $start->addDay()->timestamp];
    }

    private function insertCoupon(string $code, int $now): int
    {
        return (int) DB::table('v2_coupon')->insertGetId([
            'code' => $code,
            'name' => $code,
            'type' => 1,
            'value' => 10,
            'show' => true,
            'limit_use' => null,
            'limit_use_with_user' => null,
            'limit_plan_ids' => null,
            'limit_period' => null,
            'started_at' => $now - 86400,
            'ended_at' => $now + 86400,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function insertOrder(array $overrides): void
    {
        static $sequence = 0;
        $sequence++;
        $now = (int) ($overrides['paid_at'] ?? time());

        DB::table('v2_order')->insert(array_merge([
            'invite_user_id' => null,
            'user_id' => 1,
            'plan_id' => 1,
            'coupon_id' => null,
            'payment_id' => null,
            'type' => Order::TYPE_NEW_PURCHASE,
            'period' => 'month_price',
            'trade_no' => 'daily-report-' . str_pad((string) $sequence, 8, '0', STR_PAD_LEFT),
            'callback_no' => null,
            'total_amount' => 0,
            'handling_amount' => 0,
            'discount_amount' => 0,
            'surplus_amount' => 0,
            'surplus_credit' => 0,
            'balance_amount' => 0,
            'surplus_order_ids' => null,
            'status' => Order::STATUS_COMPLETED,
            'commission_status' => 0,
            'commission_balance' => 0,
            'actual_commission_balance' => null,
            'paid_at' => $now,
            'created_at' => $now - 86400,
            'updated_at' => $now,
        ], $overrides));
    }

    private function insertServerStat(
        int $serverId,
        string $serverType,
        int $upload,
        int $download,
        int $recordAt,
        string $recordType,
        int $updatedAt
    ): void {
        DB::table('v2_stat_server')->insert([
            'server_id' => $serverId,
            'server_type' => $serverType,
            'u' => $upload,
            'd' => $download,
            'record_type' => $recordType,
            'record_at' => $recordAt,
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ]);
    }

    private function insertUserStat(
        int $userId,
        float $rate,
        int $upload,
        int $download,
        int $recordAt,
        string $recordType,
        int $updatedAt
    ): void {
        DB::table('v2_stat_user')->insert([
            'user_id' => $userId,
            'server_rate' => $rate,
            'u' => $upload,
            'd' => $download,
            'record_type' => $recordType,
            'record_at' => $recordAt,
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ]);
    }
}
