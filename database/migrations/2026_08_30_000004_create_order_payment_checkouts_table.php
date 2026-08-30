<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('v2_order_payment_checkout')) {
            return;
        }

        Schema::create('v2_order_payment_checkout', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('order_id');
            $table->integer('payment_id');
            $table->string('provider', 64);
            $table->string('state', 16);
            $table->string('claim_token', 64)->nullable();
            $table->integer('base_amount');
            $table->integer('handling_amount')->nullable();
            $table->integer('response_type')->nullable();
            $table->text('response_data')->nullable();
            $table->integer('attempted_at')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');

            // One durable provider result per order/payment pair. This is the
            // database-level guard behind double-click/reload protection.
            $table->unique(['order_id', 'payment_id'], 'order_payment_checkout_unique');
            $table->index(['order_id', 'state'], 'order_payment_checkout_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_order_payment_checkout');
    }
};
