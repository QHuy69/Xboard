<?php

namespace Plugin\UsdtDirect\Services;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Read-only, Mainnet-pinned TronGrid client with bounded pagination and retry. */
final class TronGridClient
{
    public const MAINNET_BASE_URL = 'https://api.trongrid.io';
    public const USDT_CONTRACT = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';
    private const PAGE_SIZE = 200;
    private const RETRYABLE_STATUS = [429, 500, 502, 503, 504];

    private Closure $sleeper;

    public function __construct(
        private readonly string $apiKey,
        ?Closure $sleeper = null,
        private readonly int $maxAttempts = 4,
    ) {
        if (trim($apiKey) === '') {
            throw new \InvalidArgumentException('TronGrid API key is required.');
        }
        if ($maxAttempts < 1 || $maxAttempts > 8) {
            throw new \InvalidArgumentException('TronGrid retry attempts are out of range.');
        }
        $this->sleeper = $sleeper ?? static function (int $milliseconds): void {
            usleep($milliseconds * 1000);
        };
    }

    /**
     * Discover every solidified inbound USDT candidate in a bounded time range.
     *
     * @return list<array<string, mixed>>
     */
    public function incomingTransfers(
        string $receivingAddress,
        int $minTimestampMs,
        int $maxTimestampMs,
        int $maxPages = 25,
    ): array {
        $receivingHex = TronAddress::toHex($receivingAddress);
        if ($minTimestampMs < 0 || $maxTimestampMs <= 0 || $minTimestampMs > $maxTimestampMs) {
            throw new \InvalidArgumentException('Invalid TronGrid scan time range.');
        }
        if ($maxPages < 1 || $maxPages > 100) {
            throw new \InvalidArgumentException('TronGrid max page count is out of range.');
        }

        $url = self::MAINNET_BASE_URL . '/v1/accounts/' . rawurlencode($receivingAddress) . '/transactions/trc20';
        $parameters = [
            'only_confirmed' => 'true',
            'only_to' => 'true',
            'contract_address' => self::USDT_CONTRACT,
            'order_by' => 'block_timestamp,asc',
            'limit' => self::PAGE_SIZE,
            'min_timestamp' => $minTimestampMs,
            'max_timestamp' => $maxTimestampMs,
        ];
        $fingerprints = [];
        $transfers = [];

        for ($page = 1; $page <= $maxPages; $page++) {
            $response = $this->request('GET', $url, $parameters);
            $payload = $this->jsonObject($response, 'TronGrid transfer history');
            if (($payload['success'] ?? null) !== true || !isset($payload['data']) || !is_array($payload['data'])) {
                throw new \RuntimeException('TronGrid returned an invalid transfer-history response.');
            }

            foreach ($payload['data'] as $item) {
                $transfer = $this->validateCandidate($item, $receivingHex);
                if ($transfer !== null) {
                    $transfers[] = $transfer;
                }
            }

            $fingerprint = data_get($payload, 'meta.fingerprint');
            if ($fingerprint === null || $fingerprint === '') {
                return $transfers;
            }
            if (!is_string($fingerprint) || strlen($fingerprint) > 2048 || isset($fingerprints[$fingerprint])) {
                throw new \RuntimeException('TronGrid returned an invalid pagination fingerprint.');
            }
            $fingerprints[$fingerprint] = true;
            $parameters['fingerprint'] = $fingerprint;
        }

        throw new \RuntimeException('TronGrid scan exceeded the configured page bound; watermark was not advanced.');
    }

    /** Return a solidified, successfully executed transaction receipt. */
    public function solidifiedReceipt(string $txid): array
    {
        $txid = strtolower(trim($txid));
        if (!preg_match('/^[0-9a-f]{64}$/', $txid)) {
            throw new \InvalidArgumentException('Invalid TRON transaction ID.');
        }
        $response = $this->request(
            'POST',
            self::MAINNET_BASE_URL . '/walletsolidity/gettransactioninfobyid',
            ['value' => $txid]
        );
        $payload = $this->jsonObject($response, 'TronGrid solidified receipt');
        if ($payload === []) {
            throw new \RuntimeException('Solidified receipt is not available yet.');
        }
        if (strtolower(trim((string) ($payload['id'] ?? ''))) !== $txid) {
            throw new \RuntimeException('Solidified receipt transaction ID does not match.');
        }
        if (data_get($payload, 'receipt.result') !== 'SUCCESS') {
            throw new \RuntimeException('TRON transaction did not execute successfully.');
        }

        return $payload;
    }

    public function solidifiedBlockHash(int $blockNumber): string
    {
        if ($blockNumber < 0) {
            throw new \InvalidArgumentException('Invalid TRON block number.');
        }
        $response = $this->request(
            'POST',
            self::MAINNET_BASE_URL . '/walletsolidity/getblockbynum',
            ['num' => $blockNumber]
        );
        $payload = $this->jsonObject($response, 'TronGrid solidified block');
        $blockHash = strtolower(trim((string) ($payload['blockID'] ?? '')));
        $returnedNumber = data_get($payload, 'block_header.raw_data.number');
        if (!preg_match('/^[0-9a-f]{64}$/', $blockHash)
            || filter_var($returnedNumber, FILTER_VALIDATE_INT) === false
            || (int) $returnedNumber !== $blockNumber) {
            throw new \RuntimeException('TronGrid returned an invalid solidified block.');
        }

        return $blockHash;
    }

    private function validateCandidate(mixed $item, string $receivingHex): ?array
    {
        if (!is_array($item)) {
            throw new \RuntimeException('TronGrid returned a malformed transfer candidate.');
        }
        $type = trim((string) ($item['type'] ?? ''));
        if ($type === 'Approval') {
            return null;
        }
        if ($type !== 'Transfer') {
            throw new \RuntimeException('TronGrid returned an unknown TRC20 event type.');
        }

        $txid = strtolower(trim((string) ($item['transaction_id'] ?? '')));
        $contract = trim((string) data_get($item, 'token_info.address', ''));
        $to = trim((string) ($item['to'] ?? ''));
        $from = trim((string) ($item['from'] ?? ''));
        $timestamp = filter_var($item['block_timestamp'] ?? null, FILTER_VALIDATE_INT);
        $decimals = filter_var(data_get($item, 'token_info.decimals'), FILTER_VALIDATE_INT);

        if (!preg_match('/^[0-9a-f]{64}$/', $txid)
            || $contract !== self::USDT_CONTRACT
            || $decimals !== UsdtAmount::DECIMALS
            || TronAddress::toHex($to) !== $receivingHex
            || !TronAddress::isValidMainnet($from)
            || $timestamp === false
            || $timestamp <= 0) {
            throw new \RuntimeException('TronGrid transfer candidate failed validation.');
        }

        return [
            'transaction_id' => $txid,
            'block_timestamp' => (int) $timestamp,
            'from' => $from,
            'to' => $to,
            'value' => UsdtAmount::canonicalRaw($item['value'] ?? null),
        ];
    }

    private function request(string $method, string $url, array $payload): Response
    {
        $lastException = null;
        for ($attempt = 1; $attempt <= $this->maxAttempts; $attempt++) {
            try {
                $request = Http::acceptJson()
                    ->connectTimeout(10)
                    ->timeout(25)
                    ->withHeaders(['TRON-PRO-API-KEY' => trim($this->apiKey)]);
                $response = $method === 'GET'
                    ? $request->get($url, $payload)
                    : $request->asJson()->post($url, $payload);
            } catch (ConnectionException $exception) {
                $lastException = $exception;
                if ($attempt === $this->maxAttempts) {
                    throw new \RuntimeException('TronGrid connection failed after retries.', 0, $exception);
                }
                ($this->sleeper)($this->backoffMilliseconds($attempt, null));
                continue;
            }

            if ($response->successful()) {
                return $response;
            }
            if (!in_array($response->status(), self::RETRYABLE_STATUS, true)
                || $attempt === $this->maxAttempts) {
                throw new \RuntimeException('TronGrid request failed with HTTP ' . $response->status() . '.');
            }
            ($this->sleeper)($this->backoffMilliseconds($attempt, $response));
        }

        throw new \RuntimeException('TronGrid request failed.', 0, $lastException);
    }

    private function backoffMilliseconds(int $attempt, ?Response $response): int
    {
        $retryAfter = $response?->header('Retry-After');
        if (is_string($retryAfter) && preg_match('/^\d+$/', trim($retryAfter))) {
            return min(30000, max(250, (int) $retryAfter * 1000));
        }

        return min(10000, (250 * (2 ** ($attempt - 1))) + random_int(0, 250));
    }

    private function jsonObject(Response $response, string $operation): array
    {
        $payload = $response->json();
        if (!is_array($payload) || array_is_list($payload)) {
            throw new \RuntimeException($operation . ' returned non-object JSON.');
        }

        return $payload;
    }
}
