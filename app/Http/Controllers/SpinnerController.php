<?php

namespace App\Http\Controllers;

use App\Models\SpinLog;
use App\Models\LeaderboardEntry;
use App\Models\Quest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SpinnerController extends Controller
{
    // ── Physics constants (must match frontend exactly) ───────────────────────

    /**
     * The frontend drains energy at: dE = |speed| * 0.0012 * drainModifier
     * Points earned at:              dP = dE * 1.5
     * So the baseline ratio is: points = energy * 1.5 (before skin multiplier).
     *
     * We recompute points SERVER-SIDE from energy_used, so the client's
     * points_earned value is used only as a plausibility cross-check and
     * then DISCARDED — the server derives the real value independently.
     */
    private const POINTS_PER_ENERGY_UNIT = 1.5;
    private const PHYSICS_TOLERANCE      = 0.35; // 35% slack for float drift / timing variance

    /**
     * Maximum energy that can physically drain per 2-second sync window.
     * Max speed 22 deg/frame @ 60fps × 0.0012 × 2s = ~3.17 units. Cap at 5.0 with headroom.
     */
    private const MAX_ENERGY_PER_SYNC = 5.0;

    /**
     * Hard cap on points accepted per sync: MAX_ENERGY * RATIO * highest_multiplier(1.7) = ~12.75
     * Rounded up to 15 for safety.
     */
    private const MAX_POINTS_PER_SYNC = 15.0;

    /**
     * Per-user rate limit: max sync calls per minute.
     * Frontend syncs every 2s = 30/min normally. Allow 40 for slight bursts.
     */
    private const SYNC_RATE_LIMIT_PER_MIN = 40;

    // ── Skin tables ───────────────────────────────────────────────────────────

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

    // ── Rate limiting ─────────────────────────────────────────────────────────

    private function isSyncRateLimited(int $userId): bool
    {
        $key   = "fidge:sync_rate:{$userId}";
        $count = (int) Cache::get($key, 0);

        if ($count >= self::SYNC_RATE_LIMIT_PER_MIN) {
            return true;
        }

        Cache::put($key, $count + 1, 60);
        return false;
    }

    // ── Endpoints ─────────────────────────────────────────────────────────────

    /**
     * POST /api/spinner/sync
     *
     * Security layers:
     * 1. Hard-cap validation on inputs (max 5 energy, max 15 points per call)
     * 2. Per-user rate limit (40 calls/min max)
     * 3. Physics plausibility cross-check (points vs energy ratio)
     * 4. Server recomputes points from energy — client's points_earned is IGNORED
     * 5. Server-side skin lookup — client cannot claim a skin they don't own
     */
    public function sync(Request $request): JsonResponse
    {
        $request->validate([
            'points_earned' => ['required', 'numeric', 'min:0', 'max:' . self::MAX_POINTS_PER_SYNC],
            'energy_used'   => ['required', 'numeric', 'min:0', 'max:' . self::MAX_ENERGY_PER_SYNC],
        ]);

        $user = $request->user();

        // 1. Per-user rate limit
        if ($this->isSyncRateLimited($user->id)) {
            return response()->json(['error' => 'Too many requests. Slow down.'], 429);
        }

        $energy = $user->getTodayEnergy();

        if ($energy->energy <= 0) {
            return response()->json([
                'energy'      => 0,
                'points'      => round($user->points, 4),
                'spin_points' => round($user->spin_points, 4),
                'message'     => 'No energy remaining',
            ]);
        }

        $clientPoints = (float) $request->points_earned;
        $clientEnergy = (float) $request->energy_used;

        // 2. Physics plausibility — only check when both values are non-trivial
        if ($clientEnergy > 0.001 && $clientPoints > 0.001) {
            $expectedPoints = $clientEnergy * self::POINTS_PER_ENERGY_UNIT;
            $ratio          = $clientPoints / $expectedPoints;

            if ($ratio > (1.0 + self::PHYSICS_TOLERANCE)) {
                Log::warning('Fidge: sync physics violation', [
                    'user_id'       => $user->id,
                    'client_points' => $clientPoints,
                    'client_energy' => $clientEnergy,
                    'ratio'         => round($ratio, 3),
                ]);
                return response()->json(['error' => 'Invalid sync data.'], 422);
            }
        }

        // 3. Server recomputes points from energy (ignores client's points_earned)
        $multiplier   = $this->getSkinMultiplier($user->active_skin ?? 'Obsidian');
        $energyFactor = $this->getSkinEnergyFactor($user->active_skin ?? 'Obsidian');

        DB::transaction(function () use ($user, $energy, $clientEnergy, $multiplier, $energyFactor) {
            // Cap to window maximum regardless of what client sent
            $rawEnergyUsed = min($clientEnergy, self::MAX_ENERGY_PER_SYNC);

            // Apply skin drain factor and floor at available energy
            $energyUsed = min($rawEnergyUsed * $energyFactor, $energy->energy);

            // Points computed server-side from raw (pre-drain-factor) energy
            $pointsEarned = round($rawEnergyUsed * self::POINTS_PER_ENERGY_UNIT * $multiplier, 4);

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
     *
     * Points/energy are NOT credited here — they were already applied during /sync.
     * This endpoint only writes an audit log for quest detection purposes.
     */
    public function sessionEnd(Request $request): JsonResponse
    {
        $request->validate([
            'total_points'      => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'total_energy_used' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'duration_seconds'  => ['nullable', 'numeric', 'min:0', 'max:86400'],
        ]);

        $user = $request->user();

        // Rate limit: max 1 session-end per 5 seconds per user (prevents log spam)
        $rateKey = "fidge:session_end:{$user->id}";
        if (Cache::has($rateKey)) {
            return response()->json(['message' => 'Session logged']);
        }
        Cache::put($rateKey, 1, 5);

        SpinLog::create([
            'user_id'          => $user->id,
            'points_earned'    => $request->total_points ?? 0,
            'energy_used'      => $request->total_energy_used ?? 0,
            'duration_seconds' => $request->duration_seconds ?? 0,
            'spin_date'        => today()->toDateString(),
        ]);

        $this->checkSpinnerQuests($user);

        return response()->json(['message' => 'Session logged']);
    }

    /**
     * POST /api/spinner/watch-ad
     *
     * 2-hour cooldown between batches of 5 ads.
     * After 5 ads are watched, a 2-hour cooldown starts before the next batch.
     */
    public function watchAd(Request $request): JsonResponse
    {
        $user   = $request->user();
        $energy = $user->getTodayEnergy();

        // Rate limit: max 1 watch-ad per 15 seconds (prevents double-tap exploit)
        $rateKey = "fidge:watch_ad:{$user->id}";
        if (Cache::has($rateKey)) {
            return response()->json(['error' => 'Please wait before watching another ad.'], 429);
        }
        Cache::put($rateKey, 1, 15);

        // Check 2-hour cooldown — only applies after completing a full batch of 5
        if ($energy->ads_watched >= 5) {
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
            if ($energy->ads_watched >= 5) {
                $energy->update(['last_ad_at' => now()]);
            }
        });

        $energy->refresh();

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

    // ── Quest detection ───────────────────────────────────────────────────────

    private function checkSpinnerQuests(User $user): void
    {
        $spinCount = SpinLog::where('user_id', $user->id)->count();

        $uniqueSpinDays = SpinLog::where('user_id', $user->id)
            ->distinct('spin_date')
            ->count('spin_date');

        $todayDuration = SpinLog::where('user_id', $user->id)
            ->where('spin_date', today()->toDateString())
            ->sum('duration_seconds');

        $questChecks = [
            ['title' => 'First Spin',      'met' => $spinCount >= 1],
            ['title' => 'Spin 10 Times',   'met' => $spinCount >= 10],
            ['title' => 'Spin 50 Times',   'met' => $spinCount >= 50],
            ['title' => 'Earn 100 Points', 'met' => $user->spin_points >= 100],
            ['title' => 'Speed Demon',     'met' => $todayDuration >= 30],
            ['title' => 'Watch an Ad',     'met' => $user->getTodayEnergy()->ads_watched >= 1],
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
