<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_usdt_direct_invoice')) {
            Schema::create('v2_usdt_direct_invoice', function (Blueprint $table): void {
                $table->increments('id');
                $table->integer('order_id');
                $table->integer('checkout_id');
                $table->integer('payment_id');
                $table->string('payment_uuid', 64);
                // Only the SHA-256 digest is persisted. Possession of the raw
                // token is the capability required to open the checkout page.
                $table->char('public_token_hash', 64);
                $table->string('network', 16);
                $table->string('token_contract', 64);
                $table->string('receiving_address', 64);
                // TRC20 amounts are integers in token base units (USDT uses
                // six decimals). Never round-trip this value through FLOAT.
                $table->string('expected_amount_raw', 40);
                $table->string('exchange_rate', 40);
                $table->unsignedInteger('required_confirmations');
                $table->string('state', 24)->default('awaiting');
                $table->string('txid', 128)->nullable();
                $table->unsignedInteger('log_index')->nullable();
                $table->unsignedBigInteger('block_number')->nullable();
                $table->string('block_hash', 128)->nullable();
                $table->unsignedBigInteger('block_timestamp')->nullable();
                $table->integer('seen_at')->nullable();
                $table->integer('confirmed_at')->nullable();
                $table->integer('expires_at');
                $table->string('manual_review_reason')->nullable();
                // Address, rate, contract and confirmation policy remain
                // verifiable after an administrator rotates gateway config.
                $table->text('config_snapshot')->nullable();
                $table->integer('created_at');
                $table->integer('updated_at');

                $table->unique('order_id', 'usdt_invoice_order_unique');
                $table->unique('checkout_id', 'usdt_invoice_checkout_unique');
                $table->unique('public_token_hash', 'usdt_invoice_public_token_unique');
                $table->index(['payment_id', 'state'], 'usdt_invoice_payment_state_idx');
                // With one receiving wallet, the exact six-decimal amount is
                // the invoice discriminator. Never recycle a pair: a late
                // transfer must resolve to its old invoice, not a new order.
                $table->unique(
                    ['network', 'token_contract', 'receiving_address', 'expected_amount_raw'],
                    'usdt_invoice_amount_assignment_unique'
                );
                $table->index(['receiving_address', 'state'], 'usdt_invoice_address_state_idx');
                $table->index(['state', 'expires_at'], 'usdt_invoice_expiry_idx');
            });
        }

        if (!Schema::hasTable('v2_usdt_direct_transfer')) {
            Schema::create('v2_usdt_direct_transfer', function (Blueprint $table): void {
                $table->increments('id');
                $table->integer('invoice_id')->nullable();
                $table->string('network', 16);
                $table->string('token_contract', 64);
                $table->string('txid', 128);
                $table->unsignedInteger('log_index');
                $table->string('from_address', 64)->nullable();
                $table->string('to_address', 64);
                $table->string('amount_raw', 40);
                $table->unsignedBigInteger('block_number');
                $table->string('block_hash', 128)->nullable();
                $table->unsignedBigInteger('block_timestamp');
                $table->unsignedInteger('confirmations')->default(0);
                $table->string('state', 24)->default('seen');
                // Keep transfer-level exceptions without mutating a settled
                // invoice. This is required for duplicate payments and
                // operator reconciliation after fulfillment.
                $table->string('manual_review_reason')->nullable();
                $table->char('raw_payload_hash', 64)->nullable();
                $table->integer('created_at');
                $table->integer('updated_at');

                // A TRC20 transaction may contain several Transfer logs. The
                // log index is part of its immutable on-chain identity.
                $table->unique(
                    ['network', 'token_contract', 'txid', 'log_index'],
                    'usdt_transfer_chain_identity_unique'
                );
                $table->index(['invoice_id', 'state'], 'usdt_transfer_invoice_state_idx');
                $table->index(['to_address', 'amount_raw'], 'usdt_transfer_match_idx');
                $table->index(['network', 'block_number'], 'usdt_transfer_block_idx');
            });
        }

        if (!Schema::hasTable('v2_usdt_direct_scan_cursor')) {
            Schema::create('v2_usdt_direct_scan_cursor', function (Blueprint $table): void {
                $table->increments('id');
                $table->integer('payment_id');
                $table->string('network', 16);
                $table->string('token_contract', 64);
                $table->string('receiving_address', 64);
                $table->unsignedBigInteger('last_block_number')->default(0);
                $table->unsignedBigInteger('last_block_timestamp')->default(0);
                $table->integer('last_success_at')->nullable();
                $table->integer('last_error_at')->nullable();
                $table->string('last_error')->nullable();
                $table->integer('created_at');
                $table->integer('updated_at');

                $table->unique(
                    ['payment_id', 'network', 'token_contract', 'receiving_address'],
                    'usdt_scan_cursor_source_unique'
                );
                $table->index(['network', 'last_block_number'], 'usdt_scan_cursor_block_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_usdt_direct_scan_cursor');
        Schema::dropIfExists('v2_usdt_direct_transfer');
        Schema::dropIfExists('v2_usdt_direct_invoice');
    }
};
