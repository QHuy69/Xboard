<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsdtDirectScanCursor extends Model
{
    protected $table = 'v2_usdt_direct_scan_cursor';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'payment_id' => 'integer',
        'last_block_number' => 'integer',
        'last_block_timestamp' => 'integer',
        'last_success_at' => 'integer',
        'last_error_at' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }
}
