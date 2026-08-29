<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InviteCode extends Model
{
    protected $table = 'v2_invite_code';
    protected $dateFormat = 'U';
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'status' => 'integer',
    ];

    const STATUS_UNUSED = 0;
    const STATUS_USED = 1;
    const STATUS_DISABLED = 2;
}
