<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EnergySession extends Model
{
    protected $fillable = ['user_id', 'session_date', 'energy', 'ads_watched', 'last_ad_at'];

    protected $casts = [
        'energy'      => 'float',
        'ads_watched' => 'integer',
        'session_date'=> 'date',
        'last_ad_at'  => 'datetime',
        'last_ad_at'  => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function drain(float $amount): float
    {
        $this->energy = max(0, $this->energy - $amount);
        $this->save();
        return $this->energy;
    }

    public function refill(float $amount): float
    {
        $this->energy = min(100, $this->energy + $amount);
        $this->save();
        return $this->energy;
    }
}
