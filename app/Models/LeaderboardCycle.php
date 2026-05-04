<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class LeaderboardCycle extends Model
{
    protected $fillable = ['cycle_number', 'cycle_start', 'cycle_end'];
    protected $casts    = ['cycle_start' => 'date', 'cycle_end' => 'date'];

    public function entries() { return $this->hasMany(LeaderboardEntry::class, 'cycle_id'); }

    /**
     * Get the current active cycle, creating it if needed.
     */
    public static function current(): static
    {
        $epochStart = Carbon::parse(config('fidge.leaderboard_epoch_start', '2025-01-01'));
        $cycleDays  = (int) config('fidge.leaderboard_cycle_days', 14);
        $now        = Carbon::now();

        $elapsed    = $epochStart->diffInDays($now, false);
        $cycleNum   = (int) floor(max(0, $elapsed) / $cycleDays);
        $cycleStart = $epochStart->copy()->addDays($cycleNum * $cycleDays);
        $cycleEnd   = $cycleStart->copy()->addDays($cycleDays - 1);

        return static::firstOrCreate(
            ['cycle_number' => $cycleNum],
            ['cycle_start' => $cycleStart->toDateString(), 'cycle_end' => $cycleEnd->toDateString()]
        );
    }
}
