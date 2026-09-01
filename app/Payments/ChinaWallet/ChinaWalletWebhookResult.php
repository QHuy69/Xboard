<?php

namespace App\Payments\ChinaWallet;

use InvalidArgumentException;

final readonly class ChinaWalletWebhookResult
{
    public function __construct(
        public string $eventId,
        public string $providerReference,
        public string $tradeNo,
        public ChinaWalletPaymentStatus $status,
        public int $amountMinor,
        public string $currency = 'CNY',
    ) {
        if ($this->eventId === '' || $this->providerReference === '' || $this->tradeNo === '') {
            throw new InvalidArgumentException('China-wallet webhook identifiers are required.');
        }
        if ($this->amountMinor <= 0 || $this->currency !== 'CNY') {
            throw new InvalidArgumentException('China-wallet webhook amount or currency is invalid.');
        }
    }
}
