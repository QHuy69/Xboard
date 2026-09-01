<?php

namespace App\Payments\ChinaWallet;

use InvalidArgumentException;

final readonly class ChinaWalletPaymentRequest
{
    public function __construct(
        public ChinaWallet $wallet,
        public string $tradeNo,
        public int $amountMinor,
        public string $description,
        public string $notifyUrl,
        public string $returnUrl,
        public string $currency = 'CNY',
    ) {
        if ($this->tradeNo === '' || strlen($this->tradeNo) > 64 || preg_match('/[\x00-\x1F\x7F]/', $this->tradeNo)) {
            throw new InvalidArgumentException('China-wallet trade number is invalid.');
        }
        if ($this->amountMinor <= 0) {
            throw new InvalidArgumentException('China-wallet amount must be a positive integer in minor units.');
        }
        if ($this->currency !== 'CNY') {
            throw new InvalidArgumentException('China-wallet checkout currently accepts CNY only.');
        }
        if ($this->description === '' || mb_strlen($this->description) > 127) {
            throw new InvalidArgumentException('China-wallet description must contain 1 to 127 characters.');
        }
        $this->assertHttpsUrl($this->notifyUrl, 'notification');
        $this->assertHttpsUrl($this->returnUrl, 'return');
    }

    private function assertHttpsUrl(string $url, string $label): void
    {
        $parts = parse_url($url);
        if (!filter_var($url, FILTER_VALIDATE_URL)
            || !is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])) {
            throw new InvalidArgumentException("China-wallet {$label} URL must be an absolute HTTPS URL without credentials.");
        }
    }
}
