<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

require_once dirname(__DIR__, 2) . '/plugins-core/Telegram/Plugin.php';

class TelegramDailyBusinessAudienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_dormant_or_group_only_config_does_not_require_a_private_destination(): void
    {
        $dormant = $this->plugin([
            'enable_daily_business_report' => false,
            'daily_business_report_time' => 'not-a-time',
            'daily_business_report_chat_id' => '-100123',
            'daily_business_report_send_admin_private' => true,
        ]);
        $dormant->validateActivation();

        $groupOnly = $this->plugin([
            'enable_daily_business_report' => true,
            'daily_business_report_time' => '00:30',
            'daily_business_report_chat_id' => '-100123',
            'daily_business_report_send_admin_private' => false,
            'daily_business_report_publish_group_summary' => true,
            'node_report_chat_id' => '-100987654321',
        ]);
        $groupOnly->validateActivation();

        $this->addToAssertionCount(2);
    }

    public function test_private_report_requires_a_bound_xboard_administrator(): void
    {
        $this->insertTelegramUser('91001', false, true);
        $staffOnly = $this->plugin([
            'enable_daily_business_report' => true,
            'daily_business_report_time' => '00:30',
            'daily_business_report_chat_id' => '91001',
            'daily_business_report_send_admin_private' => true,
            'daily_business_report_publish_group_summary' => false,
        ]);

        try {
            $staffOnly->validateActivation();
            $this->fail('A staff-only Telegram binding was accepted as a commercial-report administrator.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('/bind', $exception->getMessage());
        }

        $this->insertTelegramUser('91002', true, false);
        $administrator = $this->plugin([
            'enable_daily_business_report' => true,
            'daily_business_report_time' => '00:30',
            'daily_business_report_chat_id' => '91002',
            'daily_business_report_send_admin_private' => true,
            'daily_business_report_publish_group_summary' => false,
        ]);
        $administrator->validateActivation();
        $this->assertSame('91002', $administrator->resolvedAdminChat());
    }

    public function test_public_summary_is_minimal_by_default_and_aggregates_are_opt_in(): void
    {
        $date = '2026-09-03';
        $start = CarbonImmutable::createFromFormat(
            '!Y-m-d',
            $date,
            'Asia/Ho_Chi_Minh',
        )->timestamp;
        $this->insertTelegramUser('92001', true, false, time());
        DB::table('v2_stat_server')->insert([
            'server_id' => 1,
            'server_type' => 'vless',
            'u' => 1024,
            'd' => 2048,
            'record_type' => 'd',
            'record_at' => $start,
            'created_at' => $start,
            'updated_at' => $start,
        ]);

        $minimal = implode("\n", $this->plugin([
            'daily_business_report_group_include_online_users' => false,
            'daily_business_report_group_include_traffic' => false,
        ])->publicChunks($date, 'vi'));
        $this->assertStringContainsString('Node trực tuyến: 0/0', $minimal);
        $this->assertStringNotContainsString('Người dùng trực tuyến:', $minimal);
        $this->assertStringNotContainsString('Lưu lượng tổng hợp:', $minimal);

        $expanded = implode("\n", $this->plugin([
            'daily_business_report_group_include_online_users' => true,
            'daily_business_report_group_include_traffic' => true,
        ])->publicChunks($date, 'vi'));
        $this->assertStringContainsString('Người dùng trực tuyến: 1', $expanded);
        $this->assertStringContainsString('Lưu lượng tổng hợp: 3.00 KB', $expanded);
        $this->assertStringNotContainsString('92001', $expanded);
    }

    private function plugin(array $config): object
    {
        $plugin = new class('telegram') extends \Plugin\Telegram\Plugin
        {
            /** @return list<string> */
            public function publicChunks(string $date, string $locale): array
            {
                return $this->dailyPublicReportChunks($date, $locale);
            }

            public function resolvedAdminChat(): ?string
            {
                return $this->dailyBusinessReportAdminChatId();
            }
        };
        $plugin->setConfig($config);

        return $plugin;
    }

    private function insertTelegramUser(
        string $telegramId,
        bool $isAdmin,
        bool $isStaff,
        int $lastTrafficAt = 0,
    ): void {
        static $sequence = 0;
        $sequence++;
        $now = time();
        DB::table('v2_user')->insert([
            'telegram_id' => $telegramId,
            'email' => 'telegram-report-' . $sequence . '@example.test',
            'password' => password_hash('test-password', PASSWORD_BCRYPT),
            'uuid' => sprintf('00000000-0000-0000-0000-%012d', $sequence),
            'token' => str_pad((string) $sequence, 32, '0', STR_PAD_LEFT),
            'is_admin' => $isAdmin,
            'is_staff' => $isStaff,
            't' => $lastTrafficAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
