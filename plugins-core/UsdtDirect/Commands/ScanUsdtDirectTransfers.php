<?php

namespace Plugin\UsdtDirect\Commands;

use Illuminate\Console\Command;
use Plugin\UsdtDirect\Services\UsdtDirectScanner;

final class ScanUsdtDirectTransfers extends Command
{
    protected $signature = 'usdt-direct:scan
        {--payment-id=* : Limit scanning to one or more numeric payment IDs}';

    protected $description = 'Discover and settle solidified inbound USDT TRC20 transfers';

    public function handle(UsdtDirectScanner $scanner): int
    {
        $paymentIds = [];
        foreach ((array) $this->option('payment-id') as $paymentId) {
            if (!is_string($paymentId)
                || !preg_match('/^[1-9][0-9]*$/D', $paymentId)
                || filter_var($paymentId, FILTER_VALIDATE_INT) === false) {
                $this->error('Every --payment-id must be a positive integer.');
                return self::INVALID;
            }
            $paymentIds[] = (int) $paymentId;
        }

        $result = $scanner->scanAll($paymentIds);
        foreach ($result['payments'] as $stats) {
            $this->line(sprintf(
                'payment=%d candidates=%d ignored=%d transfers=%d matched=%d settled=%d review=%d unmatched=%d expired=%d%s',
                $stats['payment_id'],
                $stats['candidates'],
                $stats['ignored'],
                $stats['transfers'],
                $stats['matched'],
                $stats['settled'],
                $stats['manual_review'],
                $stats['unmatched'],
                $stats['expired'],
                $stats['skipped'] ? ' skipped' : ''
            ));
        }
        foreach ($result['errors'] as $error) {
            $this->error(sprintf('payment=%d error=%s', $error['payment_id'], $error['error']));
        }

        return $result['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
