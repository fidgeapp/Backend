<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpinLog extends Model
{
    protected $fillable = ['user_id', 'points_earned', 'energy_used', 'duration_seconds', 'spin_date'];
    protected $casts    = ['spin_date' => 'date', 'points_earned' => 'float', 'energy_used' => 'float'];

    public function user() { return $this->belongsTo(User::class); }
}
