<?php

namespace App\Payments\ChinaWallet;

use InvalidArgumentException;

final readonly class ChinaWalletCheckoutSession
{
    public const ACTION_QR = 'qr';
    public const ACTION_REDIRECT = 'redirect';

    public function __construct(
        public string $provider,
        public string $providerReference,
        public ChinaWallet $wallet,
        public ChinaWalletPaymentStatus $status,
        public string $actionType,
        public ?string $qrPayload,
        public ?string $redirectUrl,
        public int $expiresAt,
    ) {
        if ($this->provider === '' || $this->providerReference === '') {
            throw new InvalidArgumentException('China-wallet provider identifiers are required.');
        }
        if (!in_array($this->actionType, [self::ACTION_QR, self::ACTION_REDIRECT], true)) {
            throw new InvalidArgumentException('China-wallet checkout action is unsupported.');
        }
        if ($this->actionType === self::ACTION_QR && ($this->qrPayload === null || $this->qrPayload === '')) {
            throw new InvalidArgumentException('China-wallet QR action requires a payload.');
        }
        if ($this->actionType === self::ACTION_REDIRECT && !$this->isHttpsUrl($this->redirectUrl)) {
            throw new InvalidArgumentException('China-wallet redirect action requires an HTTPS URL.');
        }
        if ($this->expiresAt <= 0) {
            throw new InvalidArgumentException('China-wallet checkout expiry is required.');
        }
    }

    private function isHttpsUrl(?string $url): bool
    {
        if ($url === null) {
            return false;
        }
        $parts = parse_url($url);
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && !empty($parts['host'])
            && !isset($parts['user'])
            && !isset($parts['pass']);
    }
}
