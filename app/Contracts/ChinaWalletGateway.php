<?php

namespace App\Contracts;

use App\Payments\ChinaWallet\ChinaWalletCheckoutSession;
use App\Payments\ChinaWallet\ChinaWalletPaymentRequest;
use App\Payments\ChinaWallet\ChinaWalletWebhookResult;

interface ChinaWalletGateway
{
    public function create(ChinaWalletPaymentRequest $request): ChinaWalletCheckoutSession;

    public function query(string $providerReference): ChinaWalletWebhookResult;

    /** Verify the provider signature against the untouched request body before parsing business fields. */
    public function verifyWebhook(array $headers, string $rawBody): ChinaWalletWebhookResult;

    /** Return the provider refund reference. Final refund success may remain asynchronous. */
    public function refund(string $providerReference, string $refundReference, int $amountMinor): string;
}
