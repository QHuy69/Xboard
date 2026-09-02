const assert = require('assert');
const fs = require('fs');

const plugin = fs.readFileSync('plugins-core/UsdtDirect/Plugin.php', 'utf8');
const config = fs.readFileSync('plugins-core/UsdtDirect/config.json', 'utf8');
const validator = fs.readFileSync('plugins-core/UsdtDirect/Services/UsdtDirectConfig.php', 'utf8');
const client = fs.readFileSync('plugins-core/UsdtDirect/Services/TronGridClient.php', 'utf8');
const parser = fs.readFileSync('plugins-core/UsdtDirect/Services/Trc20TransferParser.php', 'utf8');
const scanner = fs.readFileSync('plugins-core/UsdtDirect/Services/UsdtDirectScanner.php', 'utf8');
const command = fs.readFileSync('plugins-core/UsdtDirect/Commands/ScanUsdtDirectTransfers.php', 'utf8');

const manifest = JSON.parse(config);
assert.strictEqual(manifest.code, 'usdt_direct');
assert.strictEqual(manifest.type, 'payment');
assert.strictEqual(manifest.auto_enable, false);

for (const key of [
  'usdt_network',
  'usdt_token_contract',
  'usdt_receive_address',
  'usdt_cny_usdt_rate',
  'usdt_invoice_ttl_minutes',
  'usdt_required_confirmations',
  'usdt_trongrid_api_key',
  'usdt_scan_overlap_seconds',
  'usdt_scan_max_pages',
]) {
  assert(plugin.includes(`'${key}' => [`), `payment form is missing ${key}`);
}
assert(plugin.includes('implements PaymentInterface'));
assert(plugin.includes('public function usesGlobalPaymentConfiguration(): bool')
  && plugin.includes('return false;'));
assert(plugin.includes('validatePaymentConfigurationShape()')
  && plugin.includes('UsdtDirectConfig::validate($this->getConfig(), false)'));
assert(plugin.includes('validatePaymentConfiguration()')
  && plugin.includes('UsdtDirectConfig::validate($this->getConfig(), true)'));
assert(plugin.includes("->command('usdt-direct:scan')")
  && plugin.includes('->everyMinute()')
  && plugin.includes('->onOneServer()')
  && plugin.includes('->withoutOverlapping(5)'));
assert(command.includes("usdt-direct:scan") && command.includes("{--payment-id=*"));

assert(validator.includes("public const NETWORK = 'tron';"));
assert(parser.includes("'network' => UsdtDirectConfig::NETWORK"));
assert(parser.includes("'token_contract' => TronGridClient::USDT_CONTRACT"));
assert(!parser.includes("'network' => 'tron-mainnet'"),
  'parser network must match the invoice schema canonical value');

assert(client.includes("'only_confirmed' => 'true'")
  && client.includes("'only_to' => 'true'")
  && client.includes("'contract_address' => self::USDT_CONTRACT"));
assert(client.includes('/walletsolidity/gettransactioninfobyid')
  && client.includes('/walletsolidity/getblockbynum'));
assert(client.includes('RETRYABLE_STATUS')
  && client.includes('backoffMilliseconds')
  && client.includes("header('Retry-After')"));
assert(client.includes('pagination fingerprint')
  && client.includes('watermark was not advanced'));

assert(scanner.includes("->select(['network', 'token_contract', 'receiving_address'])")
  && scanner.includes('->distinct()'),
  'scanner must enumerate durable invoice sources, not only the current payment address');
assert(scanner.includes("$source['receiving_address']")
  && !scanner.includes("incomingTransfers(\n                $config['usdt_receive_address']"),
  'rotated historical invoice addresses must still be scanned');
assert(scanner.includes('last_block_timestamp')
  && scanner.includes('usdt_scan_overlap_seconds')
  && scanner.includes('last_error_at')
  && scanner.includes('last_error'));
assert(scanner.includes('solidifiedReceipt($txid)')
  && scanner.includes('solidifiedBlockHash((int) $blockNumber)')
  && scanner.includes('Trc20TransferParser'));
assert(scanner.includes('OrderService::settleUsdtDirectTransfer('));
assert(scanner.includes('expected_amount_raw')
  && scanner.includes("$stats['ignored']")
  && scanner.indexOf('expected_amount_raw') < scanner.indexOf('verifiedEvents('),
  'scanner must discard unallocated dust before fetching receipts');

const matcherStart = scanner.indexOf('private function matchingInvoice(');
const matcherEnd = scanner.indexOf('private function invoiceSourceQuery(', matcherStart);
const matcher = scanner.slice(matcherStart, matcherEnd);
assert(matcher.includes("->where('expected_amount_raw'"));
assert(!matcher.includes("->where('state'") && !matcher.includes('whereIn'),
  'expired and late invoices must remain matchable');
assert(scanner.includes('Expire only after a successful chain scan'));
assert(scanner.includes("'invoice_id' => null")
  && scanner.includes('UsdtDirectTransfer::STATE_MANUAL_REVIEW'));

console.log('USDT Direct plugin, solidified scanner, rotation, overlap, and late-payment invariants verified.');
