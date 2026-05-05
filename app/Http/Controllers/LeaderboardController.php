<?php

namespace App\Http\Controllers;

use App\Models\LeaderboardCycle;
use App\Models\LeaderboardEntry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class LeaderboardController extends Controller
{
    /**
     * GET /api/leaderboard
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $cycle = LeaderboardCycle::current();
        } catch (\Throwable $e) {
            return response()->json(['entries' => [], 'cycle' => null, 'me' => null]);
        }

        // ── Top 100 sorted by leaderboard_entries.points (the canonical
        //    cycle-specific score), breaking ties by entry id so users with
        //    equal scores get a deterministic, stable order. ─────────────────
        $entries = LeaderboardEntry::with('user:id,username,email,avatar_color,referral_count,points,spin_points')
            ->where('cycle_id', $cycle->id)
            ->orderByDesc('points')   // cycle-specific points — authoritative
            ->orderBy('id')           // tiebreak: earlier entry (earlier joiner) ranks higher
            ->limit(100)
            ->get()
            ->map(fn ($e, $i) => [
                'rank'           => $i + 1,
                'user_id'        => $e->user_id,
                'username'       => $e->user->username      ?? 'Unknown',
                'email'          => $e->user->email         ?? '',
                'avatar_color'   => $e->user->avatar_color  ?? '#888',
                // Use leaderboard entry points as the authoritative cycle score
                'points'         => round((float)($e->points ?? $e->user->points ?? 0), 2),
                'spin_points'    => round((float)($e->user->spin_points ?? 0), 2),
                'referral_count' => (int)($e->user->referral_count ?? $e->referral_count ?? 0),
            ])
            ->values();

        // ── "me" block ────────────────────────────────────────────────────────
        // Always returned for authenticated users. Lets the frontend show the
        // user's real global rank + points even when outside the top 100.
        $me = null;
        /** @var \App\Models\User|null $authUser */
        $authUser = $request->attributes->get('auth_user');

        if ($authUser) {
            $myEntry  = LeaderboardEntry::where('cycle_id', $cycle->id)
                ->where('user_id', $authUser->id)
                ->first();

            $myPoints = round((float)($myEntry?->points ?? $authUser->points ?? 0), 2);

            // Global rank = number of other entries with MORE points than me + 1
            $ahead = LeaderboardEntry::where('cycle_id', $cycle->id)
                ->where('user_id', '!=', $authUser->id)
                ->where('points', '>', $myPoints)
                ->count();

            $globalRank = $ahead + 1;
            $inTop100   = $entries->contains('user_id', $authUser->id);

            $me = [
                'rank'           => $globalRank,
                'in_top_100'     => $inTop100,
                'user_id'        => $authUser->id,
                'username'       => $authUser->username,
                'email'          => $authUser->email,
                'avatar_color'   => $authUser->avatar_color ?? '#888',
                'points'         => $myPoints,
                'referral_count' => (int)$authUser->referral_count,
            ];
        }

        // Cycle info in format frontend expects
        $cycleEnd  = $cycle->cycle_end ?? $cycle->ends_at ?? null;
        $cycleInfo = $cycleEnd ? [
            'name'    => 'Season ' . (($cycle->cycle_number ?? 0) + 1),
            'ends_at' => Carbon::parse($cycleEnd)->toISOString(),
        ] : null;

        return response()->json([
            'entries' => $entries,
            'cycle'   => $cycleInfo,
            'me'      => $me,
        ]);
    }
}
