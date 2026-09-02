<?php

namespace Plugin\UsdtDirect\Services;

/** Exact decimal arithmetic for six-decimal USDT amounts. */
final class UsdtAmount
{
    public const DECIMALS = 6;
    private const MAX_RATE_DECIMALS = 12;

    /**
     * Convert XBoard's integer CNY cents to the smallest USDT unit.
     * Rounding is always upward so conversion can never undercharge an order.
     */
    public static function cnyCentsToRaw(int $cnyCents, string $usdtPerCny): string
    {
        if ($cnyCents <= 0) {
            throw new \InvalidArgumentException('CNY amount must be positive.');
        }

        [$coefficient, $scale] = self::parsePositiveDecimal($usdtPerCny);
        $numerator = self::multiply((string) $cnyCents, $coefficient);
        $numerator = self::multiply($numerator, '1000000');
        $denominator = 100 * (10 ** $scale);
        [$raw, $remainder] = self::divideByInt($numerator, $denominator);
        if ($remainder !== 0) {
            $raw = self::addOne($raw);
        }
        if ($raw === '0') {
            throw new \InvalidArgumentException('Converted USDT amount is zero.');
        }

        return $raw;
    }

    /** Convert an unsigned hexadecimal integer to a canonical decimal string. */
    public static function hexToDecimal(string $hex): string
    {
        $hex = strtolower(trim($hex));
        if (str_starts_with($hex, '0x')) {
            $hex = substr($hex, 2);
        }
        if ($hex === '' || !preg_match('/^[0-9a-f]+$/', $hex)) {
            throw new \InvalidArgumentException('Amount must be an unsigned hexadecimal integer.');
        }

        $decimal = '0';
        foreach (str_split($hex) as $digit) {
            $decimal = self::multiplyByInt($decimal, 16);
            $decimal = self::addInt($decimal, hexdec($digit));
        }

        return self::canonicalRaw($decimal);
    }

    /** Validate and canonicalize an unsigned raw-unit amount. */
    public static function canonicalRaw(mixed $raw, bool $allowZero = false): string
    {
        if (!is_string($raw) && !is_int($raw)) {
            throw new \InvalidArgumentException('USDT amount must be an integer string.');
        }
        $raw = trim((string) $raw);
        if ($raw === '' || !preg_match('/^\d+$/', $raw)) {
            throw new \InvalidArgumentException('USDT amount must contain digits only.');
        }
        $raw = ltrim($raw, '0');
        $raw = $raw === '' ? '0' : $raw;
        if (!$allowZero && $raw === '0') {
            throw new \InvalidArgumentException('USDT amount must be positive.');
        }

        return $raw;
    }

    public static function formatRaw(string $raw): string
    {
        $raw = self::canonicalRaw($raw, true);
        $padded = str_pad($raw, self::DECIMALS + 1, '0', STR_PAD_LEFT);
        $whole = substr($padded, 0, -self::DECIMALS);
        $fraction = substr($padded, -self::DECIMALS);

        return $whole . '.' . $fraction;
    }

    /** @return array{string, int} */
    private static function parsePositiveDecimal(string $value): array
    {
        $value = trim($value);
        if (!preg_match('/^(?:0|[1-9]\d*)(?:\.(\d{1,' . self::MAX_RATE_DECIMALS . '}))?$/', $value, $matches)) {
            throw new \InvalidArgumentException('USDT exchange rate must be a positive decimal string.');
        }
        $fraction = $matches[1] ?? '';
        $coefficient = ltrim(str_replace('.', '', $value), '0');
        if ($coefficient === '') {
            throw new \InvalidArgumentException('USDT exchange rate must be positive.');
        }

        return [$coefficient, strlen($fraction)];
    }

    private static function multiply(string $left, string $right): string
    {
        $left = self::canonicalRaw($left, true);
        $right = self::canonicalRaw($right, true);
        if ($left === '0' || $right === '0') {
            return '0';
        }

        $result = array_fill(0, strlen($left) + strlen($right), 0);
        for ($i = strlen($left) - 1; $i >= 0; $i--) {
            for ($j = strlen($right) - 1; $j >= 0; $j--) {
                $position = $i + $j + 1;
                $total = $result[$position] + ((int) $left[$i] * (int) $right[$j]);
                $result[$position] = $total % 10;
                $result[$position - 1] += intdiv($total, 10);
            }
        }

        return ltrim(implode('', $result), '0') ?: '0';
    }

    /** @return array{string, int} */
    private static function divideByInt(string $number, int $divisor): array
    {
        if ($divisor <= 0) {
            throw new \InvalidArgumentException('Divisor must be positive.');
        }
        $quotient = '';
        $remainder = 0;
        foreach (str_split(self::canonicalRaw($number, true)) as $digit) {
            $value = ($remainder * 10) + (int) $digit;
            $quotient .= (string) intdiv($value, $divisor);
            $remainder = $value % $divisor;
        }

        return [ltrim($quotient, '0') ?: '0', $remainder];
    }

    private static function multiplyByInt(string $number, int $multiplier): string
    {
        $carry = 0;
        $result = '';
        for ($i = strlen($number) - 1; $i >= 0; $i--) {
            $value = ((int) $number[$i] * $multiplier) + $carry;
            $result = ($value % 10) . $result;
            $carry = intdiv($value, 10);
        }
        while ($carry > 0) {
            $result = ($carry % 10) . $result;
            $carry = intdiv($carry, 10);
        }

        return ltrim($result, '0') ?: '0';
    }

    private static function addInt(string $number, int $addition): string
    {
        $carry = $addition;
        $result = '';
        for ($i = strlen($number) - 1; $i >= 0; $i--) {
            $value = (int) $number[$i] + $carry;
            $result = ($value % 10) . $result;
            $carry = intdiv($value, 10);
        }
        while ($carry > 0) {
            $result = ($carry % 10) . $result;
            $carry = intdiv($carry, 10);
        }

        return ltrim($result, '0') ?: '0';
    }

    private static function addOne(string $number): string
    {
        return self::addInt($number, 1);
    }
}
