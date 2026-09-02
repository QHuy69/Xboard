<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\UsdtDirectInvoice;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class UsdtDirectCheckoutController extends Controller
{
    private const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{32,128}$/D';
    private const ADDRESS_PATTERN = '/^T[1-9A-HJ-NP-Za-km-z]{33}$/D';
    private const USDT_TRC20_CONTRACT = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';
    private const SUPPORTED_LOCALES = [
        'vi-VN', 'en-US', 'zh-CN', 'zh-TW', 'ja-JP', 'ko-KR', 'fa-IR', 'ru-RU',
    ];

    public function show(Request $request, string $opaqueToken): Response
    {
        [$invoice, $order] = $this->resolveCheckout($opaqueToken);
        $locale = $this->resolveLocale($request);
        $returnUrl = '/orders?trade_no=' . rawurlencode((string) $order->trade_no);

        return response()
            ->view('payment.usdt-direct', [
                'locale' => $locale,
                'order' => $order,
                'amountUsdt' => $this->formatUsdt((string) $invoice->expected_amount_raw),
                'receivingAddress' => (string) $invoice->receiving_address,
                'expiresAt' => $this->timestamp($invoice->expires_at),
                'statusUrl' => route('payment.usdt-direct.status', ['opaqueToken' => $opaqueToken], false),
                'qrUrl' => route('payment.usdt-direct.qr', ['opaqueToken' => $opaqueToken], false),
                'returnUrl' => $returnUrl,
                'initialStatus' => $this->checkoutStatus($invoice, $order),
            ])
            ->withHeaders($this->secureHeaders([
                'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'self'; script-src 'self'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'",
            ]));
    }

    public function status(string $opaqueToken): JsonResponse
    {
        [$invoice, $order] = $this->resolveCheckout($opaqueToken);
        $expiresAt = $this->timestamp($invoice->expires_at);

        return response()->json([
            'status' => $this->checkoutStatus($invoice, $order),
            'expires_at' => $expiresAt,
            'return_url' => '/orders?trade_no=' . rawurlencode((string) $order->trade_no),
        ])->withHeaders($this->secureHeaders());
    }

    public function qr(string $opaqueToken): Response
    {
        [$invoice] = $this->resolveCheckout($opaqueToken);
        $address = (string) $invoice->receiving_address;
        $renderer = new ImageRenderer(
            new RendererStyle(384, 12),
            new SvgImageBackEnd()
        );
        $svg = (new Writer($renderer))->writeString($address);

        return response($svg, 200, $this->secureHeaders([
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            // The SVG is generated entirely server-side. Applying CSP sandbox
            // here gives the image an opaque origin and can make Chromium
            // refuse to composite it inside a same-origin <img> together with
            // Cross-Origin-Resource-Policy: same-origin.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'",
        ]));
    }

    /** @return array{0: UsdtDirectInvoice, 1: Order} */
    private function resolveCheckout(string $opaqueToken): array
    {
        if (!preg_match(self::TOKEN_PATTERN, $opaqueToken)) {
            abort(404);
        }

        $tokenHash = hash('sha256', $opaqueToken);
        $invoice = UsdtDirectInvoice::query()
            ->where('public_token_hash', $tokenHash)
            ->first();
        if (!$invoice || !hash_equals((string) $invoice->public_token_hash, $tokenHash)) {
            abort(404);
        }

        $address = (string) $invoice->receiving_address;
        if (strtolower((string) $invoice->network) !== 'tron'
            || !hash_equals(self::USDT_TRC20_CONTRACT, (string) $invoice->token_contract)
            || !preg_match(self::ADDRESS_PATTERN, $address)
            || !preg_match('/^(?:0|[1-9][0-9]{0,39})$/D', (string) $invoice->expected_amount_raw)
            || (string) $invoice->expected_amount_raw === '0') {
            abort(503, __('Payment checkout is unavailable.'));
        }

        $order = Order::with(['payment', 'plan'])->whereKey($invoice->order_id)->first();
        if (!$order || !$order->payment || (string) $order->payment->payment !== 'UsdtDirect') {
            abort(404);
        }

        return [$invoice, $order];
    }

    private function checkoutStatus(UsdtDirectInvoice $invoice, Order $order): string
    {
        $orderStatus = (int) $order->status;
        $state = strtolower((string) $invoice->state);
        $expiresAt = $this->timestamp($invoice->expires_at);

        if (in_array($orderStatus, [Order::STATUS_PROCESSING, Order::STATUS_COMPLETED], true)) {
            return 'paid';
        }
        if ($orderStatus === Order::STATUS_CANCELLED
            || in_array($state, [UsdtDirectInvoice::STATE_CLOSED, 'cancelled', 'canceled'], true)) {
            return 'cancelled';
        }
        if (in_array($state, [UsdtDirectInvoice::STATE_SEEN, UsdtDirectInvoice::STATE_CONFIRMED], true)) {
            return 'confirming';
        }
        if ($state === UsdtDirectInvoice::STATE_MANUAL_REVIEW) {
            return 'manual_review';
        }
        if ($state === UsdtDirectInvoice::STATE_EXPIRED || ($expiresAt > 0 && $expiresAt <= time())) {
            return 'expired';
        }

        return 'pending';
    }

    private function formatUsdt(string $rawAmount): string
    {
        $normalized = ltrim($rawAmount, '0');
        $normalized = $normalized === '' ? '0' : $normalized;
        $padded = str_pad($normalized, 7, '0', STR_PAD_LEFT);

        return substr($padded, 0, -6) . '.' . substr($padded, -6);
    }

    private function timestamp(mixed $value): int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }

        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    private function resolveLocale(Request $request): string
    {
        if ($request->filled('lang')) {
            $locale = $this->normalizeLocale((string) $request->input('lang'));
            if ($locale !== null) {
                return $locale;
            }
        }
        foreach ($request->getLanguages() as $language) {
            $locale = $this->normalizeLocale((string) $language);
            if ($locale !== null) {
                return $locale;
            }
        }

        return 'en-US';
    }

    private function normalizeLocale(string $language): ?string
    {
        $normalized = strtolower(str_replace('_', '-', trim($language)));
        foreach (self::SUPPORTED_LOCALES as $supportedLocale) {
            if ($normalized === strtolower($supportedLocale)) {
                return $supportedLocale;
            }
        }
        if (preg_match('/^zh-(?:tw|hk|mo|hant)(?:-|$)/', $normalized)) {
            return 'zh-TW';
        }
        if (str_starts_with($normalized, 'zh')) {
            return 'zh-CN';
        }

        return match (strtok($normalized, '-')) {
            'vi' => 'vi-VN',
            'en' => 'en-US',
            'ja' => 'ja-JP',
            'ko' => 'ko-KR',
            'fa' => 'fa-IR',
            'ru' => 'ru-RU',
            default => null,
        };
    }

    private function secureHeaders(array $additional = []): array
    {
        return array_replace([
            'Cache-Control' => 'no-store, private, max-age=0',
            'Pragma' => 'no-cache',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
        ], $additional);
    }
}
