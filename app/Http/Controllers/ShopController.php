<?php

namespace App\Http\Controllers;

use App\Models\Skin;
use App\Models\Quest;
use App\Models\LeaderboardEntry;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ShopController extends Controller
{
    /**
     * GET /api/shop/skins  (public — no auth required, but reads token if present)
     */
    public function index(Request $request): JsonResponse
    {
        // Try to resolve user from X-Auth-Token even though route is public
        $user  = null;
        $token = $request->header('X-Auth-Token');
        if ($token) {
            $user = \App\Models\AuthToken::findValid($token);
        }
        $owned = $user ? $user->skins()->pluck('skins.id')->toArray() : [];

        $skins = Skin::where('active', true)
            ->orderByRaw("FIELD(rarity, 'Common', 'Rare', 'Epic', 'Legendary')")
            ->orderBy('gem_cost')
            ->get()
            ->map(fn ($s) => [
                'id'         => $s->id,
                'name'       => $s->name,
                'rarity'     => $s->rarity,
                'gem_cost'   => $s->gem_cost ?? 0,
                'shade'      => $s->shade,
                'color'      => $s->shade,
                'is_default' => (bool) $s->is_default,
                'image_url'  => $s->image_url,
                'owned'      => in_array($s->id, $owned) || (bool) $s->is_default,
            ]);

        return response()->json(['skins' => $skins]);
    }

    /**
     * POST /api/shop/skins/{id}/purchase
     */
    public function purchase(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $skin = Skin::findOrFail($id);

        if ($skin->is_default) {
            return response()->json(['error' => 'This skin is free — it is given at signup'], 422);
        }

        if ($user->skins()->where('skin_id', $skin->id)->exists()) {
            return response()->json(['error' => 'You already own this skin'], 422);
        }

        $gemCost = $skin->gem_cost ?? 0;
        if ($gemCost > 0 && $user->gems < $gemCost) {
            return response()->json(['error' => "Not enough gems. This skin costs {$gemCost} gems"], 422);
        }

        if ($gemCost > 0) {
            $user->decrement('gems', $gemCost);
        }

        $user->skins()->attach($skin->id, ['source' => 'purchase']);

        // Check Collector quest (own 3 skins)
        if ($user->skins()->count() >= 3) {
            $quest = Quest::where('type', 'collector')->first();
            if ($quest) {
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

        $user->refresh();

        return response()->json([
            'message' => "You now own the {$skin->name} skin!",
            'skin_id' => $skin->id,
            'gems'    => $user->gems,
            'skin'    => [
                'id'         => $skin->id,
                'name'       => $skin->name,
                'rarity'     => $skin->rarity,
                'shade'      => $skin->shade,
                'is_default' => false,
                'gem_cost'   => $skin->gem_cost,
                'owned'      => true,
            ],
        ]);
    }
}
