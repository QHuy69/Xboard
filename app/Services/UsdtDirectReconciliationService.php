<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\Order;
use App\Models\UsdtDirectInvoice;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Administrative, auditable reconciliation boundary for USDT Direct.
 *
 * Chain discovery remains the scanner's responsibility. This service exposes
 * only redacted operational evidence and never lets an operator close a live
 * awaiting/seen invoice or rewrite an already-confirmed settlement.
 */
final class UsdtDirectReconciliationService
{
    public const AUDIT_ACTION = 'usdt_direct.reconcile_close';

    public const RESOLUTIONS = [
        'cancelled_unpaid',
        'refunded',
        'credited_elsewhere',
        'duplicate_or_overpayment',
        'not_a_payment',
        'other',
    ];

    /** @return array{data:list<array<string,mixed>>,pagination:array<string,int>} */
    public function invoices(array $filters): array
    {
        $this->assertSchemaAvailable();

        $query = UsdtDirectInvoice::query()
            ->with([
                'order:id,user_id,plan_id,payment_id,trade_no,total_amount,handling_amount,status,paid_at,callback_no,created_at,updated_at',
                'order.user:id,email',
                'order.plan:id,name',
                'payment:id,name,payment,enable',
            ])
            ->withCount('transfers')
            ->orderByDesc('id');

        if (!empty($filters['state'])) {
            $query->where('state', (string) $filters['state']);
        }
        if (!empty($filters['payment_id'])) {
            $query->where('payment_id', (int) $filters['payment_id']);
        }
        if (!empty($filters['trade_no'])) {
            $tradeNo = trim((string) $filters['trade_no']);
            $query->whereHas('order', static function ($orderQuery) use ($tradeNo): void {
                $orderQuery->where('trade_no', 'like', '%' . $tradeNo . '%');
            });
        }
        if (!empty($filters['txid'])) {
            $txid = strtolower(trim((string) $filters['txid']));
            $query->whereHas('transfers', static function ($transferQuery) use ($txid): void {
                $transferQuery->where('txid', $txid);
            });
        }

        /** @var LengthAwarePaginator $page */
        $page = $query->paginate((int) ($filters['per_page'] ?? 20));
        $data = $page->getCollection()
            ->map(fn (UsdtDirectInvoice $invoice): array => $this->formatInvoice($invoice, false))
            ->values()
            ->all();

        return [
            'data' => $data,
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function detail(int $invoiceId): array
    {
        $this->assertSchemaAvailable();

        $invoice = UsdtDirectInvoice::query()
            ->with([
                'order:id,user_id,plan_id,payment_id,trade_no,total_amount,handling_amount,status,paid_at,callback_no,created_at,updated_at',
                'order.user:id,email',
                'order.plan:id,name',
                'payment:id,name,payment,enable',
                'transfers' => static fn ($query) => $query
                    ->orderBy('block_timestamp')
                    ->orderBy('txid')
                    ->orderBy('log_index'),
            ])
            ->find($invoiceId);
        if (!$invoice) {
            throw new \InvalidArgumentException(__('USDT payment invoice does not exist.'));
        }

        $result = $this->formatInvoice($invoice, true);
        $result['reconciliation_audit'] = $this->auditEntries($invoiceId);

        return $result;
    }

    /**
     * Close a manually resolved invoice and record operator, reason and time.
     *
     * A pending order may only be closed through the existing reconciled
     * cancellation boundary. Paid/cancelled orders can close an expired or
     * manual-review invoice directly. Confirmed and live invoices are immutable.
     *
     * @return array<string,mixed>
     */
    public function close(
        int $invoiceId,
        int $operatorId,
        string $resolution,
        string $reason,
        string $requestUri,
        ?string $ipAddress,
    ): array {
        $this->assertSchemaAvailable();
        if (!in_array($resolution, self::RESOLUTIONS, true)) {
            throw new \InvalidArgumentException(__('USDT reconciliation resolution is invalid.'));
        }

        return DB::transaction(function () use (
            $invoiceId,
            $operatorId,
            $resolution,
            $reason,
            $requestUri,
            $ipAddress,
        ): array {
            // Start the outer transaction before cancellation, but deliberately
            // acquire no row lock first. OrderService owns the cache/order lock
            // order; its nested DB transaction now commits together with the
            // invoice close and explicit audit record below.
            $invoiceSnapshot = UsdtDirectInvoice::query()->with('order')->find($invoiceId);
            if (!$invoiceSnapshot || !$invoiceSnapshot->order) {
                throw new \InvalidArgumentException(__('USDT payment invoice does not exist.'));
            }

            $previousState = (string) $invoiceSnapshot->state;
            if (in_array($previousState, [
                UsdtDirectInvoice::STATE_AWAITING,
                UsdtDirectInvoice::STATE_SEEN,
                UsdtDirectInvoice::STATE_CONFIRMED,
                UsdtDirectInvoice::STATE_CLOSED,
            ], true)) {
                throw new \DomainException(__('A live, confirmed or already-closed USDT invoice cannot be closed manually.'));
            }

            $cancelledByThisRequest = false;
            if ((int) $invoiceSnapshot->order->status === Order::STATUS_PENDING) {
                if ($resolution !== 'cancelled_unpaid') {
                    throw new \DomainException(__('A pending order must use the cancelled-unpaid reconciliation resolution.'));
                }
                $cancelled = (new OrderService($invoiceSnapshot->order))
                    ->cancelAfterManualPaymentReconciliation();
                if (!$cancelled) {
                    throw new \DomainException(__('The pending order changed while it was being reconciled. Refresh and try again.'));
                }
                $cancelledByThisRequest = true;
            }

            $invoice = UsdtDirectInvoice::query()->whereKey($invoiceId)->lockForUpdate()->first();
            if (!$invoice) {
                throw new \InvalidArgumentException(__('USDT payment invoice does not exist.'));
            }
            $order = Order::query()->whereKey($invoice->order_id)->lockForUpdate()->first();
            if (!$order) {
                throw new \DomainException(__('Order does not exist'));
            }

            $state = (string) $invoice->state;
            // OrderService closes this invoice as part of the pending-order
            // cancellation above. A pre-existing/concurrently closed invoice,
            // however, must not accept a second operator resolution.
            if ($state === UsdtDirectInvoice::STATE_CLOSED && !$cancelledByThisRequest) {
                throw new \DomainException(__('This USDT invoice was already reconciled by another operator.'));
            }
            if (in_array($state, [
                UsdtDirectInvoice::STATE_AWAITING,
                UsdtDirectInvoice::STATE_SEEN,
                UsdtDirectInvoice::STATE_CONFIRMED,
            ], true)) {
                throw new \DomainException(__('A live or confirmed USDT invoice cannot be closed manually.'));
            }
            if ((int) $order->status === Order::STATUS_PENDING) {
                throw new \DomainException(__('The order is still pending and cannot be marked reconciled.'));
            }

            if ($state !== UsdtDirectInvoice::STATE_CLOSED) {
                $invoice->state = UsdtDirectInvoice::STATE_CLOSED;
                $invoice->saveOrFail();
                DB::table('v2_order_payment_checkout')
                    ->where('id', $invoice->checkout_id)
                    ->where('order_id', $invoice->order_id)
                    ->where('payment_id', $invoice->payment_id)
                    ->where('provider', 'UsdtDirect')
                    ->update([
                        'state' => 'closed',
                        'claim_token' => null,
                        'response_data' => null,
                        'updated_at' => time(),
                    ]);
            }

            $auditData = [
                'invoice_id' => (int) $invoice->id,
                'order_id' => (int) $order->id,
                'trade_no' => (string) $order->trade_no,
                'resolution' => $resolution,
                'reason' => $reason,
                'previous_invoice_state' => $previousState,
                'invoice_state' => (string) $invoice->state,
                'order_status' => (int) $order->status,
            ];
            AdminAuditLog::query()->create([
                'admin_id' => $operatorId,
                'action' => self::AUDIT_ACTION,
                'method' => 'POST',
                'uri' => mb_substr($requestUri, 0, 512),
                'request_data' => json_encode(
                    $auditData,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                ),
                'ip' => $ipAddress === null ? null : mb_substr($ipAddress, 0, 128),
            ]);

            return $this->detail((int) $invoice->id);
        });
    }

    /** @return array<string,mixed> */
    private function formatInvoice(UsdtDirectInvoice $invoice, bool $includeTransfers): array
    {
        $order = $invoice->order;
        $payment = $invoice->payment;
        $data = [
            'id' => (int) $invoice->id,
            'payment_id' => (int) $invoice->payment_id,
            'payment_uuid' => (string) $invoice->payment_uuid,
            'network' => (string) $invoice->network,
            'token_contract' => (string) $invoice->token_contract,
            'receiving_address' => (string) $invoice->receiving_address,
            'expected_amount_raw' => (string) $invoice->expected_amount_raw,
            'expected_amount_usdt' => $this->formatRawAmount((string) $invoice->expected_amount_raw),
            'exchange_rate' => (string) $invoice->exchange_rate,
            'required_confirmations' => (int) $invoice->required_confirmations,
            'state' => (string) $invoice->state,
            'manual_review_reason' => $invoice->manual_review_reason === null
                ? null
                : (string) $invoice->manual_review_reason,
            'txid' => $invoice->txid === null ? null : (string) $invoice->txid,
            'log_index' => $invoice->log_index === null ? null : (int) $invoice->log_index,
            'block_number' => $invoice->block_number === null ? null : (int) $invoice->block_number,
            'block_hash' => $invoice->block_hash === null ? null : (string) $invoice->block_hash,
            'block_timestamp' => $invoice->block_timestamp === null ? null : (int) $invoice->block_timestamp,
            'seen_at' => $invoice->seen_at === null ? null : (int) $invoice->seen_at,
            'confirmed_at' => $invoice->confirmed_at === null ? null : (int) $invoice->confirmed_at,
            'expires_at' => (int) $invoice->expires_at,
            'created_at' => $this->rawTimestamp($invoice, 'created_at'),
            'updated_at' => $this->rawTimestamp($invoice, 'updated_at'),
            'transfers_count' => (int) ($invoice->transfers_count ?? $invoice->transfers->count()),
            'order' => $order ? [
                'id' => (int) $order->id,
                'trade_no' => (string) $order->trade_no,
                'status' => (int) $order->status,
                'total_amount' => (int) $order->total_amount,
                'handling_amount' => $order->handling_amount === null ? null : (int) $order->handling_amount,
                'paid_at' => $order->paid_at === null ? null : (int) $order->paid_at,
                'callback_no' => $order->callback_no === null ? null : (string) $order->callback_no,
                'user' => $order->user ? [
                    'id' => (int) $order->user->id,
                    'email' => (string) $order->user->email,
                ] : null,
                'plan' => $order->plan ? [
                    'id' => (int) $order->plan->id,
                    'name' => (string) $order->plan->name,
                ] : null,
            ] : null,
            'payment' => $payment ? [
                'id' => (int) $payment->id,
                'name' => (string) $payment->name,
                'gateway' => (string) $payment->payment,
                'enabled' => (bool) $payment->enable,
            ] : null,
        ];

        if ($includeTransfers) {
            $data['transfers'] = $invoice->transfers->map(fn ($transfer): array => [
                'id' => (int) $transfer->id,
                'txid' => (string) $transfer->txid,
                'log_index' => (int) $transfer->log_index,
                'from_address' => $transfer->from_address === null ? null : (string) $transfer->from_address,
                'to_address' => (string) $transfer->to_address,
                'amount_raw' => (string) $transfer->amount_raw,
                'amount_usdt' => $this->formatRawAmount((string) $transfer->amount_raw),
                'block_number' => (int) $transfer->block_number,
                'block_hash' => $transfer->block_hash === null ? null : (string) $transfer->block_hash,
                'block_timestamp' => (int) $transfer->block_timestamp,
                'confirmations' => (int) $transfer->confirmations,
                'state' => (string) $transfer->state,
                'manual_review_reason' => $transfer->manual_review_reason === null
                    ? null
                    : (string) $transfer->manual_review_reason,
                'created_at' => $this->rawTimestamp($transfer, 'created_at'),
                'updated_at' => $this->rawTimestamp($transfer, 'updated_at'),
            ])->values()->all();
        }

        return $data;
    }

    /** @return list<array<string,mixed>> */
    private function auditEntries(int $invoiceId): array
    {
        return AdminAuditLog::query()
            ->with('admin:id,email')
            ->where('action', self::AUDIT_ACTION)
            // Avoid silently losing an old invoice's evidence once the global
            // audit table contains more than 1,000 reconciliation entries.
            // The exact `/close` boundary prevents invoice 12 matching 123.
            ->where('uri', 'like', '%/usdt-direct/invoices/' . $invoiceId . '/close%')
            ->orderByDesc('id')
            ->limit(1000)
            ->get()
            ->map(static function (AdminAuditLog $entry) use ($invoiceId): ?array {
                $data = json_decode((string) $entry->request_data, true);
                if (!is_array($data) || (int) ($data['invoice_id'] ?? 0) !== $invoiceId) {
                    return null;
                }

                return [
                    'id' => (int) $entry->id,
                    'operator' => $entry->admin ? [
                        'id' => (int) $entry->admin->id,
                        'email' => (string) $entry->admin->email,
                    ] : ['id' => (int) $entry->admin_id, 'email' => null],
                    'resolution' => (string) ($data['resolution'] ?? ''),
                    'reason' => (string) ($data['reason'] ?? ''),
                    'previous_invoice_state' => (string) ($data['previous_invoice_state'] ?? ''),
                    'invoice_state' => (string) ($data['invoice_state'] ?? ''),
                    'order_status' => (int) ($data['order_status'] ?? -1),
                    'ip' => $entry->ip === null ? null : (string) $entry->ip,
                    'created_at' => (int) $entry->getRawOriginal('created_at'),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function formatRawAmount(string $raw): string
    {
        $raw = ltrim($raw, '0');
        $raw = $raw === '' ? '0' : $raw;
        $padded = str_pad($raw, 7, '0', STR_PAD_LEFT);

        return substr($padded, 0, -6) . '.' . substr($padded, -6);
    }

    private function rawTimestamp(object $model, string $field): int
    {
        return (int) $model->getRawOriginal($field);
    }

    private function assertSchemaAvailable(): void
    {
        foreach ([
            'v2_usdt_direct_invoice',
            'v2_usdt_direct_transfer',
            'v2_admin_audit_log',
        ] as $table) {
            if (!Schema::hasTable($table)) {
                throw new \RuntimeException(__('USDT Direct reconciliation storage is unavailable.'));
            }
        }
    }
}
