<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    protected $fillable = ['code', 'type', 'value', 'max_uses', 'used_count', 'expiry_date', 'active', 'created_by'];
    protected $casts    = ['value' => 'float', 'active' => 'boolean', 'expiry_date' => 'date'];

    public function redemptions() { return $this->hasMany(CouponRedemption::class); }

    public function isUsableBy(User $user): bool
    {
        if (!$this->active) return false;
        if ($this->expiry_date && $this->expiry_date->isPast()) return false;
        if ($this->max_uses > 0 && $this->used_count >= $this->max_uses) return false;
        if ($this->redemptions()->where('user_id', $user->id)->exists()) return false;
        return true;
    }
}
