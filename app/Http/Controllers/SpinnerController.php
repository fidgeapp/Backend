<?php

namespace App\Http\Controllers;

use App\Models\SpinLog;
use App\Models\LeaderboardEntry;
use App\Models\Quest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SpinnerController extends Controller
{
    /**
     * Skin point multipliers — higher skin = more points per energy unit
     */
    private function getSkinMultiplier(string $skinName): float
    {
        return match ($skinName) {
            'Gold'     => 1.3,
            'Sapphire' => 1.4,
            'Neon'     => 1.6,
            'Plasma'   => 1.7,
            default    => 1.0,
        };
    }

    /**
     * Skin energy drain factors — higher skin = slower energy drain = more spin time
     * Obsidian/Chrome = 1.0x (base drain)
     * Plasma = 0.65x (35% less drain — longest spin time)
     */
    private function getSkinEnergyFactor(string $skinName): float
    {
        return match ($skinName) {
            'Gold'     => 0.85,
            'Sapphire' => 0.80,
            'Neon'     => 0.72,
            'Plasma'   => 0.65,
            default    => 1.0,
        };
    }

    /**
     * POST /api/spinner/sync
     */
    public function sync(Request $request): JsonResponse
    {
        $request->validate([
            'points_earned' => ['required', 'numeric', 'min:0', 'max:500'],
            'energy_used'   => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $user   = $request->user();
        $energy = $user->getTodayEnergy();

        if ($energy->energy <= 0) {
            return response()->json([
                'energy'      => 0,
                'points'      => round($user->points, 4),
                'spin_points' => round($user->spin_points, 4),
                'message'     => 'No energy remaining',
            ]);
        }

        $multiplier   = $this->getSkinMultiplier($user->active_skin ?? 'Obsidian');
        $energyFactor = $this->getSkinEnergyFactor($user->active_skin ?? 'Obsidian');

        DB::transaction(function () use ($user, $energy, $request, $multiplier, $energyFactor) {
            $rawPoints  = (float) $request->points_earned;
            $energyUsed = min((float) $request->energy_used * $energyFactor, $energy->energy);

            $pointsEarned = round($rawPoints * $multiplier, 4);

            $user->points      = round($user->points + $pointsEarned, 4);
            $user->spin_points = round($user->spin_points + $pointsEarned, 4);
            $user->save();

            $energy->drain($energyUsed);
            LeaderboardEntry::addPoints($user, $pointsEarned);
        });

        $this->checkSpinnerQuests($user);

        $user->refresh();
        $energy->refresh();

        return response()->json([
            'points'        => round($user->points, 4),
            'spin_points'   => round($user->spin_points, 4),
            'energy'        => round($energy->energy, 2),
            'multiplier'    => $multiplier,
            'energy_factor' => $energyFactor,
        ]);
    }

    /**
     * POST /api/spinner/session-end
     */
    public function sessionEnd(Request $request): JsonResponse
    {
        $request->validate([
            'duration_seconds' => ['required', 'numeric', 'min:0', 'max:86400'],
        ]);

        $user = $request->user();

        SpinLog::create([
            'user_id'          => $user->id,
            'points_earned'    => 0,
            'energy_used'      => 0,
            'duration_seconds' => $request->duration_seconds,
            'spin_date'        => today()->toDateString(),
        ]);

        $this->checkSpinnerQuests($user);

        return response()->json(['message' => 'Session logged']);
    }

    /**
     * POST /api/spinner/watch-ad
     * 2-hour cooldown between batches of 5 ads.
     * After 5 ads are watched, a 2-hour cooldown starts before the next batch.
     */
    public function watchAd(Request $request): JsonResponse
    {
        $user   = $request->user();
        $energy = $user->getTodayEnergy();

        // Check 2-hour cooldown — only applies after completing a full batch of 5
        if ($energy->ads_watched >= 5) {
            // Check if 2 hours have passed since the batch completed (tracked by last_ad_at)
            if ($energy->last_ad_at && Carbon::parse($energy->last_ad_at)->diffInMinutes(now()) < 120) {
                $wait = 120 - (int) Carbon::parse($energy->last_ad_at)->diffInMinutes(now());
                return response()->json([
                    'error'            => "Cooldown active — next batch in {$wait} minutes",
                    'cooldown_seconds' => max(0, 7200 - (int) Carbon::parse($energy->last_ad_at)->diffInSeconds(now())),
                ], 422);
            }
            // 2 hours have passed — reset ads count for new batch
            DB::transaction(function () use ($energy) {
                $energy->update(['ads_watched' => 0, 'last_ad_at' => null]);
            });
            $energy->refresh();
        }

        if ($energy->energy >= 100) {
            return response()->json(['error' => 'Energy is already full'], 422);
        }

        DB::transaction(function () use ($energy) {
            $energy->refill(20);
            $energy->increment('ads_watched');
            // Record time when the 5th ad is watched (batch complete)
            if ($energy->ads_watched >= 5) {
                $energy->update(['last_ad_at' => now()]);
            }
        });

        $energy->refresh();

        // Calculate cooldown seconds if batch is now complete
        $cooldownSeconds = 0;
        if ($energy->ads_watched >= 5 && $energy->last_ad_at) {
            $elapsed = Carbon::parse($energy->last_ad_at)->diffInSeconds(now());
            $cooldownSeconds = max(0, 7200 - (int) $elapsed);
        }

        return response()->json([
            'energy'           => round($energy->energy, 2),
            'ads_watched'      => $energy->ads_watched,
            'cooldown_seconds' => $cooldownSeconds,
        ]);
    }

    // ── Quest detection ──────────────────────────────────────────────────────

    private function checkSpinnerQuests(User $user): void
    {
        // Total spin sessions ever
        $spinCount = SpinLog::where('user_id', $user->id)->count();

        // Unique spin days
        $uniqueSpinDays = SpinLog::where('user_id', $user->id)
            ->distinct('spin_date')
            ->count('spin_date');

        // Total duration today (for marathon)
        $todayDuration = SpinLog::where('user_id', $user->id)
            ->where('spin_date', today()->toDateString())
            ->sum('duration_seconds');

        // Check each quest
        $questChecks = [
            // First Spin — completed after 1 spin session
            ['title' => 'First Spin',      'met' => $spinCount >= 1],

            // Spin 10 Times — completed after 10 total spin sessions
            ['title' => 'Spin 10 Times',   'met' => $spinCount >= 10],

            // Spin 50 Times — completed after 50 total spin sessions
            ['title' => 'Spin 50 Times',   'met' => $spinCount >= 50],

            // Earn 100 Points — completed when total spin_points >= 100
            ['title' => 'Earn 100 Points', 'met' => $user->spin_points >= 100],

            // Speed Demon — completed after spinning for at least 30 seconds in one day
            ['title' => 'Speed Demon',     'met' => $todayDuration >= 30],

            // Energy Master — completed when energy depleted
            ['title' => 'Watch an Ad',     'met' => $user->getTodayEnergy()->ads_watched >= 1],

            // Daily Grind — 7 unique spin days
            ['title' => 'Daily Grind',     'met' => $uniqueSpinDays >= 7],
        ];

        foreach ($questChecks as $check) {
            if (!$check['met']) continue;

            $quest = Quest::where('title', $check['title'])->first();
            if (!$quest) continue;

            $pivot = $user->quests()->where('quest_id', $quest->id)->first();
            if (!$pivot || $pivot->pivot->completed) continue;

            $user->quests()->updateExistingPivot($quest->id, [
                'completed'    => true,
                'completed_at' => now(),
            ]);
            $user->increment('points', $quest->reward_points);
            $user->increment('quest_points', $quest->reward_points);
            LeaderboardEntry::addPoints($user, $quest->reward_points);
        }
    }
}
