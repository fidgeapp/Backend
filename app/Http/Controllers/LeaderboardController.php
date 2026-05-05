<?php

namespace App\Http\Controllers;

use App\Models\AuthToken;
use App\Models\LeaderboardCycle;
use App\Models\LeaderboardEntry;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class LeaderboardController extends Controller
{
    /**
     * GET /api/leaderboard
     * Public route — auth is optional. Resolves the caller manually from
     * X-Auth-Token so the "me" block is populated even without middleware.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $cycle = LeaderboardCycle::current();
        } catch (\Throwable $e) {
            return response()->json(['entries' => [], 'cycle' => null, 'me' => null]);
        }

        // ── Top 100 ───────────────────────────────────────────────────────────
        // Sort by leaderboard_entries.points (cycle-specific, authoritative).
        // Tiebreak: earlier entry id → earlier joiner ranks higher.
        $entries = LeaderboardEntry::with('user:id,username,email,avatar_color,referral_count,points,spin_points')
            ->where('cycle_id', $cycle->id)
            ->orderByDesc('points')
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->map(fn ($e, $i) => [
                'rank'           => $i + 1,
                'user_id'        => $e->user_id,
                'username'       => $e->user->username     ?? 'Unknown',
                'email'          => $e->user->email        ?? '',
                'avatar_color'   => $e->user->avatar_color ?? '#888',
                'points'         => round((float)($e->points ?? $e->user->points ?? 0), 2),
                'spin_points'    => round((float)($e->user->spin_points ?? 0), 2),
                'referral_count' => (int)($e->user->referral_count ?? $e->referral_count ?? 0),
            ])
            ->values();

        // ── "me" block ────────────────────────────────────────────────────────
        // Manually resolve the caller via X-Auth-Token (route has no middleware).
        $me       = null;
        $rawToken = $request->header('X-Auth-Token');
        $authUser = $rawToken ? AuthToken::findValid($rawToken) : null;

        if ($authUser) {
            $myEntry  = LeaderboardEntry::where('cycle_id', $cycle->id)
                ->where('user_id', $authUser->id)
                ->first();

            $myPoints = round((float)($myEntry?->points ?? $authUser->points ?? 0), 2);

            // Global rank = how many other entries have strictly more points + 1
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

        // ── Cycle info ────────────────────────────────────────────────────────
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
