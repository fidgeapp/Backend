<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GemPurchaseRequest extends Model
{
    protected $fillable = [
        'user_id', 'gem_amount', 'eth_amount',
        'wallet_address', 'tx_hash', 'status',
        'coupon_code', 'submitted_at', 'verified_at',
    ];

    protected $casts = [
        'eth_amount'   => 'float',
        'submitted_at' => 'datetime',
        'verified_at'  => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
