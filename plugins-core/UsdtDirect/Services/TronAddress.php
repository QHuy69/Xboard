<?php

namespace Plugin\UsdtDirect\Services;

/** Minimal Base58Check codec for TRON Mainnet addresses. */
final class TronAddress
{
    private const ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    public static function isValidMainnet(string $address): bool
    {
        try {
            self::toHex($address);
            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /** Return the 21-byte lowercase hex address, including the 41 network byte. */
    public static function toHex(string $address): string
    {
        $address = trim($address);
        if (!preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $address)) {
            throw new \InvalidArgumentException('Invalid TRON Mainnet address.');
        }
        $decoded = self::decodeBase58($address);
        if (strlen($decoded) !== 25) {
            throw new \InvalidArgumentException('Invalid TRON address length.');
        }
        $payload = substr($decoded, 0, 21);
        $checksum = substr($decoded, 21, 4);
        $expected = substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);
        if (!hash_equals($expected, $checksum) || ord($payload[0]) !== 0x41) {
            throw new \InvalidArgumentException('Invalid TRON address checksum or network byte.');
        }

        return strtolower(bin2hex($payload));
    }

    /** Convert a 20-byte event address (without 41) to Base58Check. */
    public static function fromEventHex(string $hex): string
    {
        $hex = strtolower(trim($hex));
        if (str_starts_with($hex, '0x')) {
            $hex = substr($hex, 2);
        }
        if (strlen($hex) === 64) {
            $hex = substr($hex, -40);
        }
        if (!preg_match('/^[0-9a-f]{40}$/', $hex)) {
            throw new \InvalidArgumentException('Invalid TRON event address.');
        }
        $payload = hex2bin('41' . $hex);
        if ($payload === false) {
            throw new \InvalidArgumentException('Invalid TRON event address encoding.');
        }
        $checksum = substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);

        return self::encodeBase58($payload . $checksum);
    }

    private static function decodeBase58(string $input): string
    {
        $bytes = [0];
        foreach (str_split($input) as $character) {
            $value = strpos(self::ALPHABET, $character);
            if ($value === false) {
                throw new \InvalidArgumentException('Invalid Base58 character.');
            }
            $carry = $value;
            for ($i = count($bytes) - 1; $i >= 0; $i--) {
                $carry += $bytes[$i] * 58;
                $bytes[$i] = $carry & 0xff;
                $carry >>= 8;
            }
            while ($carry > 0) {
                array_unshift($bytes, $carry & 0xff);
                $carry >>= 8;
            }
        }
        foreach (str_split($input) as $character) {
            if ($character !== '1') {
                break;
            }
            array_unshift($bytes, 0);
        }
        // The initial zero is a numeric accumulator, not an encoded byte.
        if (count($bytes) > 1 && $bytes[0] === 0 && $input[0] !== '1') {
            array_shift($bytes);
        }

        return pack('C*', ...$bytes);
    }

    private static function encodeBase58(string $input): string
    {
        $digits = [0];
        foreach (array_values(unpack('C*', $input)) as $byte) {
            $carry = $byte;
            for ($i = 0, $length = count($digits); $i < $length; $i++) {
                $carry += $digits[$i] << 8;
                $digits[$i] = $carry % 58;
                $carry = intdiv($carry, 58);
            }
            while ($carry > 0) {
                $digits[] = $carry % 58;
                $carry = intdiv($carry, 58);
            }
        }

        $result = '';
        for ($i = 0, $length = strlen($input); $i < $length && ord($input[$i]) === 0; $i++) {
            $result .= '1';
        }
        for ($i = count($digits) - 1; $i >= 0; $i--) {
            $result .= self::ALPHABET[$digits[$i]];
        }

        return $result;
    }
}
