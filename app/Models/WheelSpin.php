<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WheelSpin extends Model
{
    protected $fillable = ['user_id', 'gems_spent', 'prize_type', 'prize_label', 'prize_value', 'segment_index'];
    protected $casts    = ['prize_value' => 'float'];

    public function user() { return $this->belongsTo(User::class); }
}
