<?php

namespace Plugin\UsdtDirect\Services;

use App\Models\Payment;
use App\Models\UsdtDirectInvoice;
use App\Models\UsdtDirectScanCursor;
use App\Models\UsdtDirectTransfer;
use App\Services\OrderService;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Discover, verify, match and settle inbound USDT TRC20 transfers. */
final class UsdtDirectScanner
{
    private const LOCK_SECONDS = 240;

    private Closure $clientFactory;
    private Closure $settler;
    private Closure $clock;

    /**
     * Injectable boundaries keep chain discovery and settlement independently testable.
     *
     * @param null|Closure(string):TronGridClient $clientFactory
     * @param null|Closure(int,array):array $settler
     * @param null|Closure():int $clock
     */
    public function __construct(
        ?Closure $clientFactory = null,
        ?Closure $settler = null,
        ?Closure $clock = null,
    ) {
        $this->clientFactory = $clientFactory
            ?? static fn (string $apiKey): TronGridClient => new TronGridClient($apiKey);
        $this->settler = $settler
            ?? static fn (int $invoiceId, array $event): array => OrderService::settleUsdtDirectTransfer(
                $invoiceId,
                $event
            );
        $this->clock = $clock ?? static fn (): int => time();
    }

    /**
     * @param list<int> $paymentIds
     * @return array{payments:list<array<string,mixed>>,errors:list<array{payment_id:int,error:string}>}
     */
    public function scanAll(array $paymentIds = []): array
    {
        $query = Payment::query()->where('payment', 'UsdtDirect')->orderBy('id');
        if ($paymentIds !== []) {
            $query->whereIn('id', array_values(array_unique($paymentIds)));
        }

        $result = ['payments' => [], 'errors' => []];
        foreach ($query->get() as $payment) {
            try {
                $result['payments'][] = $this->scanPayment($payment);
            } catch (\Throwable $exception) {
                $message = $this->errorMessage($exception);
                $result['errors'][] = [
                    'payment_id' => (int) $payment->id,
                    'error' => $message,
                ];
                Log::error('USDT Direct scan failed.', [
                    'payment_id' => (int) $payment->id,
                    'exception' => $exception,
                ]);
            }
        }

        return $result;
    }

    /** @return array<string, int|bool> */
    public function scanPayment(Payment $payment): array
    {
        $config = UsdtDirectConfig::validateScannerConfiguration(
            is_array($payment->config) ? $payment->config : []
        );
        // Invoice rows are the durable source of truth for chain/address.
        // Rotating a payment row's wallet must not orphan an expired invoice
        // or hide a transfer that later arrives at the old address.
        $sourceRows = UsdtDirectInvoice::query()
            ->where('payment_id', (int) $payment->id)
            ->select(['network', 'token_contract', 'receiving_address'])
            ->distinct()
            ->orderBy('network')
            ->orderBy('token_contract')
            ->orderBy('receiving_address')
            ->get();
        if ($sourceRows->isEmpty()) {
            return $this->emptyStats((int) $payment->id, true);
        }

        $client = ($this->clientFactory)($config['usdt_trongrid_api_key']);
        if (!$client instanceof TronGridClient) {
            throw new \RuntimeException('USDT Direct scanner client factory returned an invalid client.');
        }

        $total = $this->emptyStats((int) $payment->id, true);
        foreach ($sourceRows as $row) {
            $source = [
                'payment_id' => (int) $payment->id,
                'network' => strtolower(trim((string) $row->network)),
                'token_contract' => trim((string) $row->token_contract),
                'receiving_address' => trim((string) $row->receiving_address),
            ];
            if ($source['network'] !== UsdtDirectConfig::NETWORK
                || !hash_equals(TronGridClient::USDT_CONTRACT, $source['token_contract'])
                || !TronAddress::isValidMainnet($source['receiving_address'])) {
                throw new \RuntimeException('A persisted USDT invoice has an invalid chain source.');
            }
            $total = $this->mergeStats(
                $total,
                $this->scanSource($source, $config, $client)
            );
        }

        return $total;
    }

    /** @return array<string, int|bool> */
    private function scanSource(array $source, array $config, TronGridClient $client): array
    {
        $invoiceQuery = $this->invoiceSourceQuery($source);
        $oldestInvoiceTimestamp = (int) $invoiceQuery->min('created_at');
        if ($oldestInvoiceTimestamp <= 0) {
            return $this->emptyStats((int) $source['payment_id'], true);
        }

        $lock = Cache::lock($this->lockKey($source), self::LOCK_SECONDS);
        if (!$lock->get()) {
            return $this->emptyStats((int) $source['payment_id'], true);
        }

        $cursor = null;
        try {
            $cursor = UsdtDirectScanCursor::query()->firstOrCreate($source, [
                'last_block_number' => 0,
                'last_block_timestamp' => 0,
            ]);
            $now = (int) ($this->clock)();
            if ($now <= 0) {
                throw new \RuntimeException('USDT Direct scanner clock is invalid.');
            }
            $maxTimestampMs = $now * 1000;
            $minTimestampMs = self::scanWindowStartTimestampMs(
                (int) $cursor->last_block_timestamp,
                $oldestInvoiceTimestamp,
                $config['usdt_scan_overlap_seconds'],
                $maxTimestampMs
            );
            $candidates = $client->incomingTransfers(
                $source['receiving_address'],
                $minTimestampMs,
                $maxTimestampMs,
                $config['usdt_scan_max_pages']
            );
            $stats = $this->emptyStats((int) $source['payment_id'], false);
            $discoveredCount = count($candidates);
            if ($candidates !== []) {
                // A public wallet can receive arbitrary dust. Verify expensive
                // Solidity receipts only for exact raw amounts allocated by
                // this durable invoice source.
                $candidateAmounts = array_values(array_unique(array_map(
                    static fn (array $candidate): string => (string) $candidate['value'],
                    $candidates
                )));
                $expectedAmounts = array_fill_keys(
                    $this->invoiceSourceQuery($source)
                        ->whereIn('expected_amount_raw', $candidateAmounts)
                        ->pluck('expected_amount_raw')
                        ->map(static fn ($amount): string => (string) $amount)
                        ->all(),
                    true
                );
                $candidates = array_values(array_filter(
                    $candidates,
                    static fn (array $candidate): bool => isset($expectedAmounts[(string) $candidate['value']])
                ));
            }
            $stats['candidates'] = count($candidates);
            $stats['ignored'] = $discoveredCount - count($candidates);
            $maxBlockNumber = (int) $cursor->last_block_number;

            foreach ($this->verifiedEvents($client, $source['receiving_address'], $candidates) as $event) {
                $stats['transfers']++;
                $maxBlockNumber = max($maxBlockNumber, (int) $event['block_number']);
                $invoice = $this->matchingInvoice($source, $event);
                if (!$invoice) {
                    $this->recordUnmatchedTransfer($event);
                    $stats['unmatched']++;
                    continue;
                }

                // Solidity receipts are final. Store a meaningful confirmation
                // count as well as the explicit solidified=true proof.
                $event['confirmations'] = max(
                    (int) ($event['confirmations'] ?? 0),
                    (int) $invoice->required_confirmations
                );
                $outcome = ($this->settler)((int) $invoice->id, $event);
                if (!is_array($outcome)) {
                    throw new \RuntimeException('USDT Direct settlement returned an invalid outcome.');
                }
                $stats['matched']++;
                $stats['settled'] += !empty($outcome['transitioned']) ? 1 : 0;
                $stats['manual_review'] += !empty($outcome['manual_review']) ? 1 : 0;
                $stats['replayed'] += !empty($outcome['replay']) ? 1 : 0;
            }

            // Expire only after a successful chain scan. This lets a transfer
            // mined inside the invoice window settle even when the worker runs
            // just after the visible countdown reaches zero.
            $stats['expired'] = $this->expireAwaitingInvoices($source, $now);

            $cursor->fill([
                'last_block_number' => $maxBlockNumber,
                // This is a high-water time, not merely the last returned row.
                // The configured overlap safely revisits delayed index entries.
                'last_block_timestamp' => $maxTimestampMs,
                'last_success_at' => $now,
                'last_error_at' => null,
                'last_error' => null,
            ])->saveOrFail();

            return $stats;
        } catch (\Throwable $exception) {
            if ($cursor) {
                $cursor->fill([
                    'last_error_at' => (int) ($this->clock)(),
                    'last_error' => $this->errorMessage($exception),
                ])->saveOrFail();
            }
            throw $exception;
        } finally {
            $lock->release();
        }
    }

    /**
     * Calculate the inclusive discovery start while preserving a trailing overlap.
     */
    public static function scanWindowStartTimestampMs(
        int $cursorTimestamp,
        int $oldestInvoiceTimestamp,
        int $overlapSeconds,
        int $maxTimestampMs
    ): int {
        if ($oldestInvoiceTimestamp < 0 || $overlapSeconds < 0 || $maxTimestampMs < 0) {
            throw new \InvalidArgumentException('Invalid USDT Direct scan window.');
        }
        // Tolerate an early deployment that may have persisted chain seconds
        // before the cursor unit was finalized as TronGrid milliseconds.
        if ($cursorTimestamp > 0 && $cursorTimestamp <= 9_999_999_999) {
            $cursorTimestamp *= 1000;
        }
        $anchor = $cursorTimestamp > 0
            ? $cursorTimestamp
            : $oldestInvoiceTimestamp * 1000;
        $start = max(0, $anchor - ($overlapSeconds * 1000));

        return min($start, $maxTimestampMs);
    }

    /**
     * @param list<array<string,mixed>> $candidates
     * @return list<array<string,mixed>>
     */
    private function verifiedEvents(
        TronGridClient $client,
        string $receivingAddress,
        array $candidates
    ): array {
        $byTransaction = [];
        foreach ($candidates as $candidate) {
            $byTransaction[(string) $candidate['transaction_id']][] = $candidate;
        }

        $parser = new Trc20TransferParser();
        $events = [];
        foreach ($byTransaction as $txid => $transactionCandidates) {
            $receipt = $client->solidifiedReceipt($txid);
            $blockNumber = filter_var($receipt['blockNumber'] ?? null, FILTER_VALIDATE_INT);
            if ($blockNumber === false || $blockNumber < 0) {
                throw new \RuntimeException('TronGrid receipt has an invalid block number.');
            }
            $blockHash = $client->solidifiedBlockHash((int) $blockNumber);
            $parsed = $parser->parse($receipt, $blockHash, $receivingAddress);
            if ($parsed === []) {
                throw new \RuntimeException('Discovered USDT transfer is absent from the Solidity receipt.');
            }

            foreach ($transactionCandidates as $candidate) {
                $matched = false;
                foreach ($parsed as $event) {
                    if (hash_equals((string) $candidate['transaction_id'], (string) $event['txid'])
                        && hash_equals((string) $candidate['from'], (string) $event['from_address'])
                        && hash_equals((string) $candidate['to'], (string) $event['to_address'])
                        && hash_equals((string) $candidate['value'], (string) $event['amount_raw'])
                        && (int) $candidate['block_timestamp'] === (int) $event['block_timestamp']) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    throw new \RuntimeException('TronGrid discovery does not match the Solidity receipt.');
                }
            }

            $payloadHash = hash('sha256', json_encode(
                ['receipt' => $receipt, 'block_hash' => $blockHash],
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ));
            foreach ($parsed as $event) {
                // A transaction can contain unrelated Transfer logs. Keep
                // only logs represented by the amount-filtered discovery rows.
                foreach ($transactionCandidates as $candidate) {
                    if (hash_equals((string) $candidate['transaction_id'], (string) $event['txid'])
                        && hash_equals((string) $candidate['from'], (string) $event['from_address'])
                        && hash_equals((string) $candidate['to'], (string) $event['to_address'])
                        && hash_equals((string) $candidate['value'], (string) $event['amount_raw'])
                        && (int) $candidate['block_timestamp'] === (int) $event['block_timestamp']) {
                        $event['confirmations'] = 0;
                        $event['raw_payload_hash'] = $payloadHash;
                        $events[$event['txid'] . ':' . $event['log_index']] = $event;
                        break;
                    }
                }
            }
        }

        return array_values($events);
    }

    /**
     * Intentionally has no state predicate: expired invoices and transfers
     * arriving after their deadline must still be bound for manual review.
     */
    private function matchingInvoice(array $source, array $event): ?UsdtDirectInvoice
    {
        return $this->invoiceSourceQuery($source)
            ->where('expected_amount_raw', (string) $event['amount_raw'])
            ->first();
    }

    private function invoiceSourceQuery(array $source): \Illuminate\Database\Eloquent\Builder
    {
        return UsdtDirectInvoice::query()
            ->where('payment_id', $source['payment_id'])
            ->where('network', $source['network'])
            ->where('token_contract', $source['token_contract'])
            ->where('receiving_address', $source['receiving_address']);
    }

    private function recordUnmatchedTransfer(array $event): void
    {
        $values = $this->transferValues($event);
        DB::transaction(function () use ($event, $values): void {
            $existing = UsdtDirectTransfer::query()
                ->where('network', $event['network'])
                ->where('token_contract', $event['token_contract'])
                ->where('txid', $event['txid'])
                ->where('log_index', $event['log_index'])
                ->lockForUpdate()
                ->first();
            if ($existing) {
                foreach ([
                    'to_address', 'amount_raw', 'block_number', 'block_hash', 'block_timestamp',
                ] as $field) {
                    if ((string) $existing->{$field} !== (string) $values[$field]) {
                        throw new \RuntimeException('A stored USDT transfer conflicts with the solidified receipt.');
                    }
                }
                // A scanner for another payment row may already have bound the
                // globally unique chain identity. Never detach or downgrade it.
                if ($existing->invoice_id !== null) {
                    return;
                }
                $existing->fill([
                    'from_address' => $values['from_address'],
                    'confirmations' => max((int) $existing->confirmations, (int) $values['confirmations']),
                    'raw_payload_hash' => $values['raw_payload_hash'],
                    'state' => UsdtDirectTransfer::STATE_MANUAL_REVIEW,
                ])->saveOrFail();
                return;
            }

            UsdtDirectTransfer::query()->create($values + [
                'invoice_id' => null,
                'state' => UsdtDirectTransfer::STATE_MANUAL_REVIEW,
            ]);
        });
    }

    /** @return array<string,int|string|null> */
    private function transferValues(array $event): array
    {
        $blockTimestamp = (int) $event['block_timestamp'];
        if ($blockTimestamp > 9_999_999_999) {
            $blockTimestamp = intdiv($blockTimestamp, 1000);
        }

        return [
            'network' => (string) $event['network'],
            'token_contract' => (string) $event['token_contract'],
            'txid' => (string) $event['txid'],
            'log_index' => (int) $event['log_index'],
            'from_address' => isset($event['from_address']) ? (string) $event['from_address'] : null,
            'to_address' => (string) $event['to_address'],
            'amount_raw' => (string) $event['amount_raw'],
            'block_number' => (int) $event['block_number'],
            'block_hash' => isset($event['block_hash']) ? (string) $event['block_hash'] : null,
            'block_timestamp' => $blockTimestamp,
            'confirmations' => (int) ($event['confirmations'] ?? 0),
            'raw_payload_hash' => isset($event['raw_payload_hash'])
                ? (string) $event['raw_payload_hash']
                : null,
        ];
    }

    private function expireAwaitingInvoices(array $source, int $now): int
    {
        $expired = 0;
        $ids = $this->invoiceSourceQuery($source)
            ->where('state', UsdtDirectInvoice::STATE_AWAITING)
            ->where('expires_at', '<=', $now)
            ->orderBy('id')
            ->pluck('id');
        foreach ($ids as $invoiceId) {
            if (OrderService::expireUsdtDirectInvoice((int) $invoiceId, $now)) {
                $expired++;
            }
        }

        return $expired;
    }

    /** @return array<string,int|bool> */
    private function emptyStats(int $paymentId, bool $skipped): array
    {
        return [
            'payment_id' => $paymentId,
            'skipped' => $skipped,
            'candidates' => 0,
            'ignored' => 0,
            'transfers' => 0,
            'matched' => 0,
            'settled' => 0,
            'manual_review' => 0,
            'replayed' => 0,
            'unmatched' => 0,
            'expired' => 0,
        ];
    }

    /** @return array<string,int|bool> */
    private function mergeStats(array $total, array $source): array
    {
        $total['skipped'] = (bool) $total['skipped'] && (bool) $source['skipped'];
        foreach ([
            'candidates', 'ignored', 'transfers', 'matched', 'settled', 'manual_review',
            'replayed', 'unmatched', 'expired',
        ] as $field) {
            $total[$field] = (int) $total[$field] + (int) $source[$field];
        }

        return $total;
    }

    private function lockKey(array $source): string
    {
        return 'usdt-direct-scan:' . hash('sha256', implode('|', [
            $source['payment_id'],
            $source['network'],
            $source['token_contract'],
            $source['receiving_address'],
        ]));
    }

    private function errorMessage(\Throwable $exception): string
    {
        $message = trim(preg_replace('/\s+/u', ' ', $exception->getMessage()) ?? '');

        return mb_substr($message === '' ? get_class($exception) : $message, 0, 240);
    }
}
