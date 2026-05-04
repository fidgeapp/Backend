<?php

namespace App\Http\Controllers;

use App\Models\WheelSpin;
use App\Models\Skin;
use App\Models\Quest;
use App\Models\User;
use App\Models\LeaderboardEntry;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class WheelController extends Controller
{
    const SPIN_COST = 2; // gems

    // Must match the frontend SEGMENTS array exactly (same index order)
    const SEGMENTS = [
        ['label' => '50 PTS',  'prize' => '50 Points',  'type' => 'points', 'value' => 50,  'color' => '#96CEB4', 'weight' => 30],
        ['label' => '1 💎',    'prize' => '1 Gem',       'type' => 'gems',   'value' => 1,   'color' => '#4ECDC4', 'weight' => 20],
        ['label' => '250 PTS', 'prize' => '250 Points', 'type' => 'points', 'value' => 250, 'color' => '#FF6B6B', 'weight' => 22],
        ['label' => '1 PCEDO', 'prize' => '1 $PCEDO',  'type' => 'pcedo',  'value' => 1,   'color' => '#BB8FCE', 'weight' => 12],
        ['label' => '500 PTS', 'prize' => '500 Points', 'type' => 'points', 'value' => 500, 'color' => '#FFE66D', 'weight' => 18],
        ['label' => '2 💎',    'prize' => '2 Gems',      'type' => 'gems',   'value' => 2,   'color' => '#45B7D1', 'weight' => 10],
        ['label' => '5 PCEDO', 'prize' => '5 $PCEDO',  'type' => 'pcedo',  'value' => 5,   'color' => '#9B59B6', 'weight' => 5 ],
        ['label' => '10 💎',   'prize' => '10 Gems',     'type' => 'gems',   'value' => 10,  'color' => '#F7DC6F', 'weight' => 3 ],
        ['label' => '10 PCEDO','prize' => '10 $PCEDO',  'type' => 'pcedo',  'value' => 10,  'color' => '#8E44AD', 'weight' => 3 ],
        ['label' => '100 PCEDO','prize'=> '100 $PCEDO', 'type' => 'pcedo',  'value' => 100, 'color' => '#A855F7', 'weight' => 1 ],
    ];

    /**
     * POST /api/wheel/spin
     *
     * Deducts 2 gems, picks a server-side weighted random segment,
     * awards the prize, and returns the segment index so the frontend
     * can animate to the exact winning position.
     */
    public function spin(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->gems < self::SPIN_COST) {
            return response()->json(['error' => 'Not enough gems'], 422);
        }

        DB::transaction(function () use ($user, &$result, &$winIndex) {
            // Deduct gems
            $user->decrement('gems', self::SPIN_COST);
            $user->refresh();

            // Pick winner server-side (prevents client manipulation)
            $winIndex = $this->weightedRandom();
            $segment  = self::SEGMENTS[$winIndex];

            // Award prize
            if ($segment['type'] === 'points') {
                $user->increment('points', $segment['value']);
                $user->increment('spin_points', $segment['value']);
                LeaderboardEntry::addPoints($user, $segment['value']);
            } elseif ($segment['type'] === 'gems') {
                $user->increment('gems', $segment['value']);
            } elseif ($segment['type'] === 'pcedo') {
                $user->increment('pcedo_earned', $segment['value']);
            } elseif ($segment['type'] === 'skin') {
                // Pick a random skin the user doesn't already own
                $ownedIds = $user->skins()->pluck('skins.id')->toArray();
                $skin = Skin::where('active', true)
                    ->whereNotIn('id', $ownedIds)
                    ->inRandomOrder()
                    ->first();
                // Fallback to Obsidian if user owns everything
                if (!$skin) {
                    $skin = Skin::where('name', 'Obsidian')->first();
                }
                if ($skin && !$user->skins()->where('skin_id', $skin->id)->exists()) {
                    $user->skins()->attach($skin->id, ['source' => 'wheel']);
                    // Update prize label to reflect actual skin won
                    $result['prize'] = $skin->name . ' Skin!';
                    // Check collector quest
                    $this->checkCollectorQuest($user);
                }
            }

            // Log the spin
            WheelSpin::create([
                'user_id'       => $user->id,
                'gems_spent'    => self::SPIN_COST,
                'prize_type'    => $segment['type'],
                'prize_label'   => $segment['prize'],
                'prize_value'   => $segment['value'],
                'segment_index' => $winIndex,
            ]);

            $result = $segment;
        });

        $user->refresh();

        return response()->json([
            'result' => [
                'segment_index' => $winIndex,
                'prize'         => $result,
            ],
            'user' => [
                'gems'         => $user->gems,
                'points'       => round($user->points, 4),
                'spin_points'  => round($user->spin_points, 4),
                'pcedo_earned' => round($user->pcedo_earned, 4),
            ],
        ]);
    }

    /**
     * GET /api/wheel/segments
     * Returns the wheel configuration so the frontend can stay in sync.
     */
    public function segments(): JsonResponse
    {
        return response()->json(['segments' => self::SEGMENTS]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function weightedRandom(): int
    {
        $totalWeight = array_sum(array_column(self::SEGMENTS, 'weight'));
        $rand = mt_rand(1, $totalWeight);
        foreach (self::SEGMENTS as $i => $segment) {
            $rand -= $segment['weight'];
            if ($rand <= 0) return $i;
        }
        return count(self::SEGMENTS) - 1;
    }

    private function checkCollectorQuest(User $user): void
    {
        if ($user->skins()->count() >= 3) {
            $quest = Quest::where('type', 'collector')->first();
            if (!$quest) return;
            $pivot = $user->quests()->where('quest_id', $quest->id)->first();
            if ($pivot && !$pivot->pivot->completed) {
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
}
