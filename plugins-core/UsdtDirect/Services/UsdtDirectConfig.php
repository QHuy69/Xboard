<?php

namespace Plugin\UsdtDirect\Services;

/** Validate and normalize the per-payment configuration used by checkout and scanning. */
final class UsdtDirectConfig
{
    public const NETWORK = 'tron';
    public const DEFAULT_TTL_MINUTES = 30;
    public const DEFAULT_REQUIRED_CONFIRMATIONS = 20;
    public const DEFAULT_SCAN_OVERLAP_SECONDS = 600;
    public const DEFAULT_SCAN_MAX_PAGES = 25;

    /**
     * @return array{
     *   usdt_network:string,usdt_token_contract:string,usdt_receive_address:string,
     *   usdt_cny_usdt_rate:string,usdt_invoice_ttl_minutes:int,
     *   usdt_required_confirmations:int,usdt_trongrid_api_key:string,
     *   usdt_scan_overlap_seconds:int,usdt_scan_max_pages:int
     * }
     */
    public static function validate(array $config, bool $requireComplete): array
    {
        $network = strtolower(self::text($config, 'usdt_network', self::NETWORK));
        $contract = self::text($config, 'usdt_token_contract', TronGridClient::USDT_CONTRACT);
        $address = self::text($config, 'usdt_receive_address');
        $rate = self::text($config, 'usdt_cny_usdt_rate');
        $apiKey = self::text($config, 'usdt_trongrid_api_key');

        if ($network !== '' && $network !== self::NETWORK) {
            throw new \InvalidArgumentException(__('USDT Direct only supports TRON Mainnet.'));
        }
        if ($contract !== '' && !hash_equals(TronGridClient::USDT_CONTRACT, $contract)) {
            throw new \InvalidArgumentException(__('USDT Direct only supports the official USDT TRC20 contract.'));
        }
        if ($address !== '' && !TronAddress::isValidMainnet($address)) {
            throw new \InvalidArgumentException(__('USDT receiving address must be a valid TRON Mainnet address.'));
        }
        // Keep this exactly compatible with OrderService's immutable checkout
        // snapshot and integer conversion boundary.
        if ($rate !== '' && (!preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,6})?$/D', $rate)
            || preg_match('/^0(?:\.0+)?$/D', $rate))) {
            throw new \InvalidArgumentException(__('USDT-per-CNY exchange rate must be a positive decimal with at most 6 decimal places.'));
        }
        if ($apiKey !== '' && (strlen($apiKey) > 256 || preg_match('/[\x00-\x1F\x7F]/', $apiKey))) {
            throw new \InvalidArgumentException(__('TronGrid API key is invalid.'));
        }

        $ttl = self::integer(
            $config,
            'usdt_invoice_ttl_minutes',
            self::DEFAULT_TTL_MINUTES,
            5,
            120
        );
        $confirmations = self::integer(
            $config,
            'usdt_required_confirmations',
            self::DEFAULT_REQUIRED_CONFIRMATIONS,
            1,
            100
        );
        $overlap = self::integer(
            $config,
            'usdt_scan_overlap_seconds',
            self::DEFAULT_SCAN_OVERLAP_SECONDS,
            60,
            3600
        );
        $maxPages = self::integer(
            $config,
            'usdt_scan_max_pages',
            self::DEFAULT_SCAN_MAX_PAGES,
            1,
            100
        );

        if ($requireComplete) {
            $missing = [];
            foreach ([
                'usdt_receive_address' => [__('TRON receiving address'), $address],
                'usdt_cny_usdt_rate' => [__('USDT-per-CNY exchange rate'), $rate],
                'usdt_trongrid_api_key' => [__('TronGrid API key'), $apiKey],
            ] as [$label, $value]) {
                if ($value === '') {
                    $missing[] = $label;
                }
            }
            if ($missing !== []) {
                throw new \InvalidArgumentException(__('USDT Direct payment method cannot be enabled. Please configure: :fields.', [
                    'fields' => implode(', ', $missing),
                ]));
            }
        }

        return [
            'usdt_network' => $network === '' ? self::NETWORK : $network,
            'usdt_token_contract' => $contract === '' ? TronGridClient::USDT_CONTRACT : $contract,
            'usdt_receive_address' => $address,
            'usdt_cny_usdt_rate' => $rate,
            'usdt_invoice_ttl_minutes' => $ttl,
            'usdt_required_confirmations' => $confirmations,
            'usdt_trongrid_api_key' => $apiKey,
            'usdt_scan_overlap_seconds' => $overlap,
            'usdt_scan_max_pages' => $maxPages,
        ];
    }

    /**
     * Validate only the live credentials and bounds needed by the scanner.
     *
     * Wallet, contract and exchange-rate values for an issued invoice are
     * frozen on that invoice. Requiring the current checkout fields here would
     * orphan historical/expired invoices after an administrator disables the
     * method and clears fields that the scanner does not use.
     *
     * @return array{usdt_trongrid_api_key:string,usdt_scan_overlap_seconds:int,usdt_scan_max_pages:int}
     */
    public static function validateScannerConfiguration(array $config): array
    {
        $apiKey = self::text($config, 'usdt_trongrid_api_key');
        if ($apiKey === ''
            || strlen($apiKey) > 256
            || preg_match('/[\x00-\x1F\x7F]/', $apiKey)) {
            throw new \InvalidArgumentException(__('TronGrid API key is invalid.'));
        }

        return [
            'usdt_trongrid_api_key' => $apiKey,
            'usdt_scan_overlap_seconds' => self::integer(
                $config,
                'usdt_scan_overlap_seconds',
                self::DEFAULT_SCAN_OVERLAP_SECONDS,
                60,
                3600
            ),
            'usdt_scan_max_pages' => self::integer(
                $config,
                'usdt_scan_max_pages',
                self::DEFAULT_SCAN_MAX_PAGES,
                1,
                100
            ),
        ];
    }

    private static function text(array $config, string $key, string $default = ''): string
    {
        $value = $config[$key] ?? $default;
        if (!is_string($value) && !is_int($value) && $value !== null) {
            throw new \InvalidArgumentException(__("USDT Direct configuration {$key} must be text."));
        }

        return trim((string) $value);
    }

    private static function integer(
        array $config,
        string $key,
        int $default,
        int $minimum,
        int $maximum
    ): int {
        $value = $config[$key] ?? $default;
        if ($value === '' || $value === null) {
            $value = $default;
        }
        if ((!is_string($value) && !is_int($value))
            || filter_var($value, FILTER_VALIDATE_INT) === false
            || (int) $value < $minimum
            || (int) $value > $maximum) {
            throw new \InvalidArgumentException(__("USDT Direct configuration {$key} is invalid."));
        }

        return (int) $value;
    }
}
