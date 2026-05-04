<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AuthToken extends Model
{
    protected $fillable = ['user_id', 'token', 'expires_at'];
    protected $casts    = ['expires_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function issue(User $user): string
    {
        // Clean up old tokens for this user
        static::where('user_id', $user->id)
              ->where('expires_at', '<', now())
              ->delete();

        $token = bin2hex(random_bytes(32)); // 64 char hex token

        static::create([
            'user_id'    => $user->id,
            'token'      => $token,
            'expires_at' => now()->addDays(30),
        ]);

        return $token;
    }

    public static function findValid(string $token): ?User
    {
        $record = static::where('token', $token)
                        ->where('expires_at', '>', now())
                        ->first();

        return $record?->user;
    }
}
