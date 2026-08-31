<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;

final class CoinPaymentsCheckoutSnapshot
{
    public const VERSION = 1;

    /** @var list<string> */
    private const REQUIRED_STRING_KEYS = [
        'coinpayments_client_id',
        'coinpayments_client_secret',
        'coinpayments_invoice_currency_id',
        'coinpayments_api_base',
        'coinpayments_webhook_url',
    ];

    public static function encrypt(array $snapshot): string
    {
        self::assertValid($snapshot);

        return Crypt::encryptString(json_encode(
            $snapshot,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));
    }

    public static function decrypt(string $encrypted): array
    {
        if (trim($encrypted) === '') {
            throw new \UnexpectedValueException('CoinPayments checkout configuration snapshot is empty.');
        }

        $snapshot = json_decode(Crypt::decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($snapshot)) {
            throw new \UnexpectedValueException('CoinPayments checkout configuration snapshot is invalid.');
        }
        self::assertValid($snapshot);

        return $snapshot;
    }

    public static function assertValid(array $snapshot): void
    {
        $snapshotVersion = filter_var($snapshot['snapshot_version'] ?? null, FILTER_VALIDATE_INT);
        if ($snapshotVersion === false || (int) $snapshotVersion !== self::VERSION) {
            throw new \UnexpectedValueException('Unsupported CoinPayments checkout configuration snapshot.');
        }

        foreach (self::REQUIRED_STRING_KEYS as $key) {
            $value = $snapshot[$key] ?? null;
            if (!is_string($value) || trim($value) === '') {
                throw new \UnexpectedValueException("CoinPayments checkout configuration snapshot is missing {$key}.");
            }
        }

        $paymentId = filter_var($snapshot['payment_id'] ?? null, FILTER_VALIDATE_INT);
        if ($paymentId === false || (int) $paymentId <= 0) {
            throw new \UnexpectedValueException('CoinPayments checkout configuration snapshot has an invalid payment ID.');
        }
        $paymentUuid = $snapshot['payment_uuid'] ?? null;
        if (!is_string($paymentUuid) || trim($paymentUuid) === '') {
            throw new \UnexpectedValueException('CoinPayments checkout configuration snapshot has an invalid payment UUID.');
        }

        $paymentCurrency = $snapshot['coinpayments_payment_currency'] ?? '';
        if (!is_string($paymentCurrency)) {
            throw new \UnexpectedValueException('CoinPayments checkout configuration snapshot has an invalid payment currency.');
        }

        $rate = $snapshot['coinpayments_cny_invoice_rate'] ?? null;
        if (!is_string($rate) || !is_numeric($rate) || (float) $rate <= 0) {
            throw new \UnexpectedValueException('CoinPayments checkout configuration snapshot has an invalid exchange rate.');
        }

        $maxAge = $snapshot['coinpayments_webhook_max_age'] ?? null;
        if (!is_int($maxAge) || $maxAge < 60 || $maxAge > 900) {
            throw new \UnexpectedValueException('CoinPayments checkout configuration snapshot has an invalid webhook validity window.');
        }
    }

    public static function expectedAmount(int $baseAmount, ?int $handlingAmount, mixed $rateValue): string
    {
        if (!is_scalar($rateValue) || !is_numeric($rateValue) || (float) $rateValue <= 0) {
            throw new \UnexpectedValueException('CoinPayments exchange rate is invalid.');
        }

        $amount = number_format(
            (($baseAmount + (int) ($handlingAmount ?? 0)) / 100) * (float) $rateValue,
            8,
            '.',
            ''
        );
        if ((float) $amount <= 0) {
            throw new \UnexpectedValueException('CoinPayments invoice amount is invalid.');
        }

        return $amount;
    }
}
