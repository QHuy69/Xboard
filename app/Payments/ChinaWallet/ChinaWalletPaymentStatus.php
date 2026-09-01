<?php

namespace App\Payments\ChinaWallet;

enum ChinaWalletPaymentStatus: string
{
    case PENDING = 'pending';
    case PAID = 'paid';
    case CANCELLED = 'cancelled';
    case EXPIRED = 'expired';
    case REFUNDING = 'refunding';
    case REFUNDED = 'refunded';
    case FAILED = 'failed';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::PAID,
            self::CANCELLED,
            self::EXPIRED,
            self::REFUNDED,
            self::FAILED,
        ], true);
    }
}
