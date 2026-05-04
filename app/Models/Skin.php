<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Skin extends Model
{
    protected $fillable = [
        'name', 'rarity', 'price_usd', 'gem_cost',
        'shade', 'image_url', 'active', 'is_default',
    ];

    protected $casts = [
        'price_usd'  => 'float',
        'gem_cost'   => 'integer',
        'active'     => 'boolean',
        'is_default' => 'boolean',
    ];

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_skins')
            ->withPivot('source')
            ->withTimestamps();
    }
}
