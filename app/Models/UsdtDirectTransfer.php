<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsdtDirectTransfer extends Model
{
    public const STATE_SEEN = 'seen';
    public const STATE_CONFIRMED = 'confirmed';
    public const STATE_SETTLED = 'settled';
    public const STATE_REVERTED = 'reverted';
    public const STATE_MANUAL_REVIEW = 'manual_review';

    protected $table = 'v2_usdt_direct_transfer';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'invoice_id' => 'integer',
        'log_index' => 'integer',
        'block_number' => 'integer',
        'block_timestamp' => 'integer',
        'confirmations' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(UsdtDirectInvoice::class, 'invoice_id');
    }
}
