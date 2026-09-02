<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UsdtDirectInvoice extends Model
{
    public const STATE_AWAITING = 'awaiting';
    public const STATE_SEEN = 'seen';
    public const STATE_CONFIRMED = 'confirmed';
    public const STATE_EXPIRED = 'expired';
    public const STATE_MANUAL_REVIEW = 'manual_review';
    public const STATE_CLOSED = 'closed';

    protected $table = 'v2_usdt_direct_invoice';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'order_id' => 'integer',
        'checkout_id' => 'integer',
        'payment_id' => 'integer',
        'required_confirmations' => 'integer',
        'log_index' => 'integer',
        'block_number' => 'integer',
        'block_timestamp' => 'integer',
        'seen_at' => 'integer',
        'confirmed_at' => 'integer',
        'expires_at' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(UsdtDirectTransfer::class, 'invoice_id');
    }
}
