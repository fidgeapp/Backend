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
            return response()->json(['entries' => [], 'cycle' => null]);
        }

        // Top 100 by points
        $entries = LeaderboardEntry::with('user:id,username,email,avatar_color,referral_count,points,spin_points')
            ->where('cycle_id', $cycle->id)
            ->orderByDesc('points')
            ->limit(100)
            ->get()
            ->map(fn ($e, $i) => [
                'rank'           => $i + 1,
                'username'  => $e->user->username  ?? 'Unknown',
                'email' => $e->user->email ?? '',
                'avatar_color'   => $e->user->avatar_color   ?? '#888',
                'points'         => round($e->user->points ?? $e->points, 2),
                'spin_points'    => round($e->user->spin_points ?? 0, 2),
                'referral_count' => $e->user->referral_count ?? 0,
            ])
            ->values();

        // Cycle info in format frontend expects
        $cycleEnd = $cycle->cycle_end ?? $cycle->ends_at ?? null;
        $cycleInfo = $cycleEnd ? [
            'name'    => 'Season ' . (($cycle->cycle_number ?? 0) + 1),
            'ends_at' => Carbon::parse($cycleEnd)->toISOString(),
        ] : null;

        return response()->json([
            'entries' => $entries,
            'cycle'   => $cycleInfo,
        ]);
    }
}
