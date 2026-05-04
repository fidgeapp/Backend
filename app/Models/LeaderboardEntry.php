<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaderboardEntry extends Model
{
    protected $fillable = ['cycle_id', 'user_id', 'points', 'referral_count'];
    protected $casts    = ['points' => 'float'];

    public function cycle() { return $this->belongsTo(LeaderboardCycle::class, 'cycle_id'); }
    public function user()  { return $this->belongsTo(User::class); }

    /**
     * Increment points for user in current cycle.
     */
    public static function addPoints(User $user, float $amount): void
    {
        $cycle = LeaderboardCycle::current();
        $entry = static::firstOrCreate(
            ['cycle_id' => $cycle->id, 'user_id' => $user->id],
            ['points' => 0, 'referral_count' => $user->referral_count]
        );
        $entry->increment('points', $amount);
    }

    /**
     * Sync referral count for user in current cycle.
     */
    public static function syncReferrals(User $user): void
    {
        $cycle = LeaderboardCycle::current();
        static::updateOrCreate(
            ['cycle_id' => $cycle->id, 'user_id' => $user->id],
            ['referral_count' => $user->referral_count]
        );
    }
}
