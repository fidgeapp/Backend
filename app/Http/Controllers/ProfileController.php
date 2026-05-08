<?php

namespace App\Http\Controllers;

use App\Models\Skin;
use App\Models\Quest;
use App\Models\SpinLog;
use App\Models\WheelSpin;
use App\Models\LeaderboardEntry;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ProfileController extends Controller
{
    const POINTS_PER_GEM   = 1000;

    /**
     * GET /api/profile
     */
    public function show(Request $request): JsonResponse
    {
        $user   = $request->user();
        $energy = $user->getTodayEnergy();

        // Purchased/won skins from pivot table
        $purchasedSkins = $user->skins()->get()->map(fn ($s) => [
            'id'         => $s->id,
            'name'       => $s->name,
            'rarity'     => $s->rarity,
            'shade'      => $s->shade,
            'color'      => $s->shade,
            'is_default' => (bool) $s->is_default,
            'gem_cost'   => $s->gem_cost ?? 0,
            'image_url'  => $s->image_url,
            'source'     => $s->pivot->source,
        ]);

        // Merge in default skins (free for everyone) not already in the list
        $purchasedSkinIds = $purchasedSkins->pluck('id')->toArray();
        $defaultSkins = \App\Models\Skin::where('is_default', true)
            ->whereNotIn('id', $purchasedSkinIds)
            ->get()
            ->map(fn ($s) => [
                'id'         => $s->id,
                'name'       => $s->name,
                'rarity'     => $s->rarity,
                'shade'      => $s->shade,
                'color'      => $s->shade,
                'is_default' => true,
                'gem_cost'   => 0,
                'image_url'  => $s->image_url,
                'source'     => 'default',
            ]);

        $ownedSkins = $purchasedSkins->concat($defaultSkins)->values();

        // Ensure every active quest is assigned to this user (backfills existing users).
        // Only INSERT missing rows — never touch rows that already exist (completed or not).
        $activeQuestIds   = Quest::where('active', true)->pluck('id');
        $assignedQuestIds = $user->quests()->pluck('quest_id');
        $missingQuestIds  = $activeQuestIds->diff($assignedQuestIds);
        foreach ($missingQuestIds as $qid) {
            $user->quests()->attach($qid, ['completed' => false]);
        }

        // Quests — return keys matching frontend FidgeQuest interface
        $quests = Quest::where('active', true)->get()->map(function ($q) use ($user) {
            $pivot = $user->quests()->where('quest_id', $q->id)->first();
            return [
                'id'            => $q->id,
                'title'         => $q->title,
                'description'   => $q->description,
                'type'          => $q->type,
                'reward_points' => $q->reward_points,
                'active'        => true,
                'pivot'         => [
                    'completed'    => $pivot ? (bool) $pivot->pivot->completed : false,
                    'completed_at' => $pivot ? $pivot->pivot->completed_at : null,
                ],
            ];
        });

        // Referrals — active = spun within last 3 days AND has >= 10,000 points
        $threeDaysAgo = now()->subDays(3)->toDateString();
        $referrals = $user->referrals()
            ->select('username', 'created_at', 'spin_points', 'id')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($r) => [
                'username' => $r->username,
                'joined'   => $r->created_at->toDateString(),
                'active'   => $r->spin_points >= 10000 &&
                              \App\Models\SpinLog::where('user_id', $r->id)
                                  ->where('spin_date', '>=', $threeDaysAgo)
                                  ->exists(),
                'points'   => round($r->spin_points, 2),
            ]);

        return response()->json([
            'user' => [
                'id'             => $user->id,
                'email'    => $user->email,
                'username' => $user->username,
                'avatar_color'   => $user->avatar_color,
                'points'         => round($user->points, 4),
                'spin_points'    => round($user->spin_points, 4),
                'quest_points'   => round($user->quest_points, 4),
                'pcedo_earned'   => round($user->pcedo_earned, 4),
                'gems'           => $user->gems,
                'referral_code'  => $user->referral_code,
                'referral_count' => $user->referral_count,
                'active_skin'    => $user->active_skin,
                'energy'         => round($energy->energy, 2),
                'ads_watched'    => $energy->ads_watched,
            ],
            'owned_skins' => $ownedSkins,
            'quests'      => $quests,
            'referrals'   => $referrals,
        ]);
    }

    /**
     * POST /api/profile/convert-points
     * Body: { points: number }
     */
    public function convertPoints(Request $request): JsonResponse
    {
        $request->validate([
            'points' => ['required', 'numeric', 'min:' . self::POINTS_PER_GEM],
        ]);

        $user = $request->user();
        $pts  = (float) $request->points;

        if ($pts > $user->points) {
            return response()->json(['error' => 'Not enough points'], 422);
        }

        $gemsEarned = (int) floor($pts / self::POINTS_PER_GEM);
        $ptsSpent   = $gemsEarned * self::POINTS_PER_GEM;

        DB::transaction(function () use ($user, $ptsSpent, $gemsEarned) {
            $user->decrement('points', $ptsSpent);
            $user->increment('gems', $gemsEarned);
        });

        $user->refresh();

        return response()->json([
            'message' => "Converted to {$gemsEarned} gems",
            'gems'    => $user->gems,
            'points'  => round($user->points, 4),
        ]);
    }

    /**
     * POST /api/profile/withdraw-gems
     * Body: { gems: number }
     */
    /**
     * POST /api/profile/withdraw-pcedo
     * User requests to withdraw their earned PCEDO to an ETH wallet.
     * Records the request — admin processes payouts manually.
     * Body: { amount: float, wallet_address: string }
     */
    public function withdrawPcedo(Request $request): JsonResponse
    {
        $request->validate([
            'amount'         => ['required', 'numeric', 'min:100'],
            'wallet_address' => ['required', 'string', 'min:10', 'max:100'],
        ]);

        $user       = $request->user();
        $amount     = round((float) $request->amount, 4);
        $fee        = round($amount * 0.005, 4);   // 0.5% fee
        $netAmount  = round($amount - $fee, 4);

        if ($amount < 100) {
            return response()->json(['error' => 'Minimum withdrawal is 100 $PCEDO'], 422);
        }

        if ($amount > $user->pcedo_earned) {
            return response()->json(['error' => 'Insufficient PCEDO balance'], 422);
        }

        // Deduct from balance and record withdrawal
        DB::transaction(function () use ($user, $amount, $netAmount, $fee, $request) {
            $user->decrement('pcedo_earned', $amount);
            DB::table('pcedo_withdrawals')->insert([
                'user_id'        => $user->id,
                'amount'         => $netAmount,
                'fee'            => $fee,
                'wallet_address' => trim($request->wallet_address),
                'status'         => 'pending',
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        });

        $user->refresh();

        return response()->json([
            'message'      => "Withdrawal of {$netAmount} \$PCEDO submitted (fee: {$fee} \$PCEDO). You'll receive it within 24–48 hours.",
            'pcedo_earned' => round($user->pcedo_earned, 4),
            'fee'          => $fee,
            'net_amount'   => $netAmount,
        ]);
    }

        public function setSkin(Request $request): JsonResponse
    {
        $request->validate(['skin_name' => ['required', 'string', 'max:50']]);

        $user = $request->user();
        $skin = Skin::where('name', $request->skin_name)->first();

        if (!$skin) {
            return response()->json(['error' => 'Skin not found'], 404);
        }

        // Allow equipping default skins freely; others require ownership
        if (!$skin->is_default && !$user->skins()->where('skin_id', $skin->id)->exists()) {
            return response()->json(['error' => 'You do not own this skin'], 403);
        }

        $user->update(['active_skin' => $skin->name]);

        return response()->json([
            'active_skin' => $user->active_skin,
            'message'     => "{$skin->name} equipped",
        ]);
    }

    /**
     * POST /api/profile/quests/{id}/confirm
     * Manually confirm a quest that can be self-verified (referral, wheel spin, watch ad).
     * Spin-based quests are auto-confirmed by the spinner controller.
     */
    public function confirmQuest(Request $request, int $id): JsonResponse
    {
        $user  = $request->user();
        $quest = Quest::findOrFail($id);

        // Only allow manual confirmation for certain quest types
        $manualTypes = ['collector', 'energy_master', 'first_spin'];
        if (!in_array($quest->type, $manualTypes)) {
            return response()->json(['error' => 'This quest is confirmed automatically — keep spinning!'], 422);
        }

        $pivot = $user->quests()->where('quest_id', $quest->id)->first();
        if (!$pivot) {
            return response()->json(['error' => 'Quest not assigned'], 404);
        }
        if ($pivot->pivot->completed) {
            return response()->json(['error' => 'Already completed'], 422);
        }

        // Validate the quest condition is actually met
        $met = false;
        switch ($quest->type) {
            case 'collector':
                // First Referral — check referral count
                $met = $user->referral_count >= 1;
                break;
            case 'energy_master':
                // Watch an Ad — check if they have watched any ad today
                $met = $user->getTodayEnergy()->ads_watched >= 1;
                break;
            case 'first_spin':
                // Wheel Spin — check wheel spin table
                if (str_contains(strtolower($quest->title), 'wheel')) {
                    $met = \App\Models\WheelSpin::where('user_id', $user->id)->exists();
                } else {
                    $met = SpinLog::where('user_id', $user->id)->exists();
                }
                break;
        }

        if (!$met) {
            return response()->json(['error' => 'Quest condition not met yet — complete the task first!'], 422);
        }

        DB::transaction(function () use ($user, $quest) {
            $user->quests()->updateExistingPivot($quest->id, [
                'completed'    => true,
                'completed_at' => now(),
            ]);
            if ($quest->reward_points > 0) {
                $user->increment('points', $quest->reward_points);
                $user->increment('quest_points', $quest->reward_points);
                LeaderboardEntry::addPoints($user, $quest->reward_points);
            }
        });

        $user->refresh();

        return response()->json([
            'message'        => "Quest \"" . $quest->title . "\" completed! +" . $quest->reward_points . " points",
            'reward_points'  => $quest->reward_points,
            'points'         => round($user->points, 4),
            'quest_points'   => round($user->quest_points, 4),
        ]);
    }


    /**
     * GET /api/profile/withdrawals
     * Returns the user's PCEDO withdrawal history.
     */
    public function myWithdrawals(Request $request): JsonResponse
    {
        $user = $request->user();
        $rows = DB::table('pcedo_withdrawals')
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn ($r) => [
                'id'             => $r->id,
                'amount'         => $r->amount,
                'wallet_address' => $r->wallet_address,
                'status'         => $r->status,
                'created_at'     => $r->created_at,
                'processed_at'   => $r->processed_at,
            ]);

        return response()->json(['withdrawals' => $rows]);
    }

    /**
     * DELETE /api/profile/withdrawals/{id}
     * User can delete their own PENDING withdrawal request.
     * Refunds the PCEDO back to their balance.
     */
    public function deleteWithdrawal(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $row  = DB::table('pcedo_withdrawals')
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$row) {
            return response()->json(['error' => 'Withdrawal not found'], 404);
        }

        if ($row->status !== 'pending') {
            return response()->json(['error' => 'Only pending withdrawals can be deleted'], 422);
        }

        DB::transaction(function () use ($user, $row) {
            // Refund PCEDO
            $user->increment('pcedo_earned', $row->amount);
            DB::table('pcedo_withdrawals')->where('id', $row->id)->delete();
        });

        $user->refresh();

        return response()->json([
            'message'      => 'Withdrawal cancelled. ' . $row->amount . ' $PCEDO refunded.',
            'pcedo_earned' => round($user->pcedo_earned, 4),
        ]);
    }

}
