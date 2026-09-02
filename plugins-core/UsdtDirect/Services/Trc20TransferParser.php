<?php

namespace Plugin\UsdtDirect\Services;

/** Parse canonical USDT Transfer logs from a solidified transaction receipt. */
final class Trc20TransferParser
{
    public const TRANSFER_TOPIC = 'ddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
    private const USDT_HEX_WITH_NETWORK = '41a614f803b6fd780986a42c78ec9c7f77e6ded13c';

    /**
     * @return list<array{
     *   network:string,token_contract:string,txid:string,log_index:int,from_address:string,
     *   to_address:string,amount_raw:string,block_number:int,block_hash:string,
     *   block_timestamp:int,receipt_result:string,solidified:bool,confirmed:bool
     * }>
     */
    public function parse(array $receipt, string $blockHash, string $receivingAddress): array
    {
        $receivingHex = TronAddress::toHex($receivingAddress);
        $txid = strtolower(trim((string) ($receipt['id'] ?? '')));
        $blockHash = strtolower(trim($blockHash));
        $blockNumber = filter_var($receipt['blockNumber'] ?? null, FILTER_VALIDATE_INT);
        $blockTimestamp = filter_var($receipt['blockTimeStamp'] ?? null, FILTER_VALIDATE_INT);

        if (!preg_match('/^[0-9a-f]{64}$/', $txid)
            || !preg_match('/^[0-9a-f]{64}$/', $blockHash)
            || $blockNumber === false
            || $blockNumber < 0
            || $blockTimestamp === false
            || $blockTimestamp <= 0
            || data_get($receipt, 'receipt.result') !== 'SUCCESS'
            || !isset($receipt['log'])
            || !is_array($receipt['log'])) {
            throw new \InvalidArgumentException('Invalid solidified TRON receipt.');
        }

        $transfers = [];
        foreach ($receipt['log'] as $logIndex => $log) {
            if (!is_array($log)) {
                throw new \InvalidArgumentException('Malformed TRON receipt log.');
            }
            $contractHex = $this->normaliseContractHex($log['address'] ?? null);
            if ($contractHex !== substr(self::USDT_HEX_WITH_NETWORK, 2)) {
                continue;
            }
            $topics = $log['topics'] ?? null;
            if (!is_array($topics) || count($topics) < 3) {
                throw new \InvalidArgumentException('Malformed USDT Transfer topics.');
            }
            $topic0 = $this->normaliseWord($topics[0] ?? null, 'event signature');
            if ($topic0 !== self::TRANSFER_TOPIC) {
                // Other events emitted by the real USDT contract are not payments.
                continue;
            }
            $fromWord = $this->normaliseWord($topics[1] ?? null, 'sender address');
            $toWord = $this->normaliseWord($topics[2] ?? null, 'recipient address');
            $toHex = '41' . substr($toWord, -40);
            if (!hash_equals($receivingHex, $toHex)) {
                continue;
            }
            $amountWord = $this->normaliseWord($log['data'] ?? null, 'transfer amount');
            $amountRaw = UsdtAmount::hexToDecimal($amountWord);
            if ($amountRaw === '0') {
                continue;
            }

            $transfers[] = [
                // Match the immutable values persisted by OrderService.
                'network' => UsdtDirectConfig::NETWORK,
                'token_contract' => TronGridClient::USDT_CONTRACT,
                'txid' => $txid,
                'log_index' => (int) $logIndex,
                'from_address' => TronAddress::fromEventHex(substr($fromWord, -40)),
                'to_address' => TronAddress::fromEventHex(substr($toWord, -40)),
                'amount_raw' => $amountRaw,
                'block_number' => (int) $blockNumber,
                'block_hash' => $blockHash,
                'block_timestamp' => (int) $blockTimestamp,
                'receipt_result' => 'SUCCESS',
                'solidified' => true,
                'confirmed' => true,
            ];
        }

        return $transfers;
    }

    private function normaliseContractHex(mixed $value): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Malformed TRON contract log address.');
        }
        $value = strtolower(trim($value));
        if (str_starts_with($value, '0x')) {
            $value = substr($value, 2);
        }
        if (strlen($value) === 42 && str_starts_with($value, '41')) {
            $value = substr($value, 2);
        }
        if (!preg_match('/^[0-9a-f]{40}$/', $value)) {
            throw new \InvalidArgumentException('Malformed TRON contract log address.');
        }

        return $value;
    }

    private function normaliseWord(mixed $value, string $label): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException('Malformed TRON ' . $label . '.');
        }
        $value = strtolower(trim($value));
        if (str_starts_with($value, '0x')) {
            $value = substr($value, 2);
        }
        if (!preg_match('/^[0-9a-f]{64}$/', $value)) {
            throw new \InvalidArgumentException('Malformed TRON ' . $label . '.');
        }

        return $value;
    }
}
