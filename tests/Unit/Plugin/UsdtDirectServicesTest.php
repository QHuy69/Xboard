<?php

namespace Tests\Unit\Plugin;

use PHPUnit\Framework\TestCase;
use Plugin\UsdtDirect\Services\Trc20TransferParser;
use Plugin\UsdtDirect\Services\TronAddress;
use Plugin\UsdtDirect\Services\TronGridClient;
use Plugin\UsdtDirect\Services\UsdtAmount;
use Plugin\UsdtDirect\Services\UsdtDirectConfig;
use Plugin\UsdtDirect\Services\UsdtDirectScanner;

require_once dirname(__DIR__, 3) . '/plugins-core/UsdtDirect/Services/TronAddress.php';
require_once dirname(__DIR__, 3) . '/plugins-core/UsdtDirect/Services/UsdtAmount.php';
require_once dirname(__DIR__, 3) . '/plugins-core/UsdtDirect/Services/TronGridClient.php';
require_once dirname(__DIR__, 3) . '/plugins-core/UsdtDirect/Services/UsdtDirectConfig.php';
require_once dirname(__DIR__, 3) . '/plugins-core/UsdtDirect/Services/Trc20TransferParser.php';
require_once dirname(__DIR__, 3) . '/plugins-core/UsdtDirect/Services/UsdtDirectScanner.php';

final class UsdtDirectServicesTest extends TestCase
{
    public function test_tron_address_codec_validates_mainnet_checksum(): void
    {
        $hex = TronAddress::toHex(TronGridClient::USDT_CONTRACT);

        $this->assertSame('41a614f803b6fd780986a42c78ec9c7f77e6ded13c', $hex);
        $this->assertSame(
            TronGridClient::USDT_CONTRACT,
            TronAddress::fromEventHex(substr($hex, 2))
        );
        $this->assertFalse(TronAddress::isValidMainnet(substr(TronGridClient::USDT_CONTRACT, 0, -1) . '1'));
    }

    public function test_amount_conversion_is_exact_and_rounds_up_one_raw_unit(): void
    {
        $this->assertSame('140000', UsdtAmount::cnyCentsToRaw(100, '0.14'));
        $this->assertSame('1', UsdtAmount::cnyCentsToRaw(1, '0.000001'));
        $this->assertSame('1.234567', UsdtAmount::formatRaw('1234567'));
    }

    public function test_parser_emits_order_service_canonical_network_and_columns(): void
    {
        $eventAddress = substr(TronAddress::toHex(TronGridClient::USDT_CONTRACT), 2);
        $addressTopic = str_repeat('0', 24) . $eventAddress;
        $receipt = [
            'id' => str_repeat('a', 64),
            'blockNumber' => 123456,
            'blockTimeStamp' => 1_700_000_000_000,
            'receipt' => ['result' => 'SUCCESS'],
            'log' => [[
                'address' => $eventAddress,
                'topics' => [
                    Trc20TransferParser::TRANSFER_TOPIC,
                    $addressTopic,
                    $addressTopic,
                ],
                'data' => str_pad(dechex(1_234_567), 64, '0', STR_PAD_LEFT),
            ]],
        ];

        $events = (new Trc20TransferParser())->parse(
            $receipt,
            str_repeat('b', 64),
            TronGridClient::USDT_CONTRACT
        );

        $this->assertCount(1, $events);
        $this->assertSame(UsdtDirectConfig::NETWORK, $events[0]['network']);
        $this->assertSame('tron', $events[0]['network']);
        $this->assertSame(TronGridClient::USDT_CONTRACT, $events[0]['token_contract']);
        $this->assertArrayNotHasKey('contract', $events[0]);
        $this->assertSame('1234567', $events[0]['amount_raw']);
        $this->assertTrue($events[0]['solidified']);
    }

    public function test_cursor_window_overlaps_high_water_and_bootstraps_from_oldest_invoice(): void
    {
        $this->assertSame(
            1_699_999_400_000,
            UsdtDirectScanner::scanWindowStartTimestampMs(
                1_700_000_000_000,
                1_600_000_000,
                600,
                1_800_000_000_000
            )
        );
        $this->assertSame(
            1_599_999_400_000,
            UsdtDirectScanner::scanWindowStartTimestampMs(
                0,
                1_600_000_000,
                600,
                1_800_000_000_000
            )
        );
        // Legacy seconds are normalized before overlap is applied.
        $this->assertSame(
            1_699_999_400_000,
            UsdtDirectScanner::scanWindowStartTimestampMs(
                1_700_000_000,
                1_600_000_000,
                600,
                1_800_000_000_000
            )
        );
    }
}
