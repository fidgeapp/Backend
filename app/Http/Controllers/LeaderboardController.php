<?php

namespace App\Http\Controllers;

use App\Models\AuthToken;
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
     *
     * Uses user.points as the authoritative score — same source as the
     * profile page — so leaderboard rankings always match what users see
     * on their profile, regardless of leaderboard_entries state.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $cycle = LeaderboardCycle::current();
        } catch (\Throwable $e) {
            return response()->json(['entries' => [], 'cycle' => null, 'me' => null]);
        }

        // ── Top 100 ───────────────────────────────────────────────────────────
        // Join leaderboard_entries with users and ORDER BY users.points —
        // the same field the profile page displays. This guarantees the
        // leaderboard score always matches the profile score.
        // Tiebreak: user.id ASC (earlier registrant ranks higher on equal score).
        $rows = LeaderboardEntry::with('user:id,username,email,avatar_color,referral_count,points,spin_points')
            ->where('cycle_id', $cycle->id)
            ->join('users', 'users.id', '=', 'leaderboard_entries.user_id')
            ->where('users.is_banned', false)
            ->orderByDesc('users.points')
            ->orderBy('users.id')
            ->limit(100)
            ->select('leaderboard_entries.*')
            ->get();

        $entries = $rows->map(fn ($e, $i) => [
            'rank'           => $i + 1,
            'user_id'        => $e->user_id,
            'username'       => $e->user->username     ?? 'Unknown',
            'email'          => $e->user->email        ?? '',
            'avatar_color'   => $e->user->avatar_color ?? '#888',
            // Use user.points — same as profile page
            'points'         => round((float)($e->user->points ?? 0), 2),
            'spin_points'    => round((float)($e->user->spin_points ?? 0), 2),
            'referral_count' => (int)($e->user->referral_count ?? 0),
        ])->values();

        // ── "me" block ────────────────────────────────────────────────────────
        // Manually resolve caller via X-Auth-Token (public route, no middleware).
        $me       = null;
        $rawToken = $request->header('X-Auth-Token');
        $authUser = $rawToken ? AuthToken::findValid($rawToken) : null;

        if ($authUser) {
            $myPoints = round((float)($authUser->points ?? 0), 2);

            // Global rank = how many other participants have strictly more points
            $ahead = LeaderboardEntry::where('cycle_id', $cycle->id)
                ->where('user_id', '!=', $authUser->id)
                ->join('users', 'users.id', '=', 'leaderboard_entries.user_id')
                ->where('users.is_banned', false)
                ->where('users.points', '>', $myPoints)
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
