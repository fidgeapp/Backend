<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Quest extends Model
{
    protected $fillable = ['title', 'description', 'reward_points', 'type', 'active'];
    protected $casts    = ['active' => 'boolean'];

    public function users() { return $this->belongsToMany(User::class, 'user_quests')->withPivot('completed', 'completed_at'); }
}
