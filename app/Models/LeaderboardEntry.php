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
     *
     * FIX: When creating a brand-new entry (user has never had one this cycle),
     * we seed it with the user's CURRENT points total instead of 0.
     * This handles the case where a user earned points before their leaderboard
     * entry was first created (e.g. they registered before the cycle record
     * existed, or the entry was accidentally missing).
     */
    public static function addPoints(User $user, float $amount): void
    {
        $cycle = LeaderboardCycle::current();

        // Use updateOrCreate so we can detect if this is an insert vs update.
        // On insert: seed with user's full current points (not just $amount).
        // On update: just increment by $amount.
        $existing = static::where('cycle_id', $cycle->id)
                          ->where('user_id', $user->id)
                          ->lockForUpdate()
                          ->first();

        if ($existing) {
            // Entry already exists — normal incremental update
            $existing->increment('points', $amount);
        } else {
            // First entry for this user this cycle — seed with their full total
            // so no historic points are lost from before the entry existed.
            static::create([
                'cycle_id'       => $cycle->id,
                'user_id'        => $user->id,
                'points'         => round($user->points + $amount, 4),
                'referral_count' => $user->referral_count ?? 0,
            ]);
        }
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
