<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\UsdtDirectInvoice;
use App\Services\UsdtDirectReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UsdtDirectReconciliationController extends Controller
{
    public function __construct(private readonly UsdtDirectReconciliationService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'state' => 'nullable|string|in:' . implode(',', [
                UsdtDirectInvoice::STATE_AWAITING,
                UsdtDirectInvoice::STATE_SEEN,
                UsdtDirectInvoice::STATE_CONFIRMED,
                UsdtDirectInvoice::STATE_EXPIRED,
                UsdtDirectInvoice::STATE_MANUAL_REVIEW,
                UsdtDirectInvoice::STATE_CLOSED,
            ]),
            'payment_id' => 'nullable|integer|min:1',
            'trade_no' => 'nullable|string|max:128',
            'txid' => 'nullable|string|regex:/^[a-fA-F0-9]{64}$/',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        return response()->json($this->service->invoices($filters));
    }

    public function show(int $invoiceId): JsonResponse
    {
        try {
            return $this->success($this->service->detail($invoiceId));
        } catch (\InvalidArgumentException $exception) {
            return $this->fail([404, $exception->getMessage()]);
        } catch (\RuntimeException $exception) {
            return $this->fail([503, $exception->getMessage()]);
        }
    }

    public function close(Request $request, int $invoiceId): JsonResponse
    {
        // Validate the reason users actually see in the audit trail. Without
        // trimming first, a value padded with spaces can satisfy `min:8` while
        // persisting an empty or misleading explanation.
        if ($request->has('reason') && is_string($request->input('reason'))) {
            $request->merge(['reason' => trim((string) $request->input('reason'))]);
        }
        $params = $request->validate([
            'resolution' => 'required|string|in:' . implode(',', UsdtDirectReconciliationService::RESOLUTIONS),
            'reason' => 'required|string|min:8|max:1000',
        ]);
        $operator = $request->user();
        if (!$operator || !$operator->is_admin) {
            return $this->fail([403, __('Unauthorized')]);
        }

        try {
            return $this->success($this->service->close(
                $invoiceId,
                (int) $operator->id,
                (string) $params['resolution'],
                (string) $params['reason'],
                (string) $request->getRequestUri(),
                $request->getClientIp(),
            ));
        } catch (\InvalidArgumentException $exception) {
            return $this->fail([404, $exception->getMessage()]);
        } catch (\DomainException $exception) {
            return $this->fail([409, $exception->getMessage()]);
        } catch (\RuntimeException $exception) {
            return $this->fail([503, $exception->getMessage()]);
        }
    }
}
