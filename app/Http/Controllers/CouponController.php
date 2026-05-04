<?php

namespace App\Http\Controllers;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\LeaderboardEntry;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class CouponController extends Controller
{
    /**
     * POST /api/coupons/redeem
     * Body: { code: "FIDGE10" }
     */
    public function redeem(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:30']]);

        $user = $request->user();
        $code = strtoupper(trim($request->code));

        $coupon = Coupon::where('code', $code)->first();

        if (!$coupon) {
            return response()->json(['error' => 'Invalid coupon code'], 422);
        }

        if (!$coupon->isUsableBy($user)) {
            if (!$coupon->active) {
                return response()->json(['error' => 'This code has been deactivated'], 422);
            }
            if ($coupon->expiry_date && $coupon->expiry_date->isPast()) {
                return response()->json(['error' => 'This code has expired'], 422);
            }
            if ($coupon->max_uses > 0 && $coupon->used_count >= $coupon->max_uses) {
                return response()->json(['error' => 'This code has reached its usage limit'], 422);
            }
            if ($coupon->redemptions()->where('user_id', $user->id)->exists()) {
                return response()->json(['error' => 'You already redeemed this code'], 422);
            }
            return response()->json(['error' => 'Code cannot be used'], 422);
        }

        DB::transaction(function () use ($coupon, $user) {
            // Record redemption
            CouponRedemption::create(['coupon_id' => $coupon->id, 'user_id' => $user->id]);
            $coupon->increment('used_count');

            // Award prize
            if ($coupon->type === 'gems') {
                $user->increment('gems', (int) $coupon->value);
            } elseif ($coupon->type === 'points') {
                $user->increment('points', $coupon->value);
                $user->increment('spin_points', $coupon->value);
                LeaderboardEntry::addPoints($user, $coupon->value);
            }
        });

        $user->refresh();

        $label = $coupon->type === 'gems'
            ? "{$coupon->value} 💎 Gems"
            : "{$coupon->value} Points";

        return response()->json([
            'message' => "Redeemed! You got {$label}",
            'gems'    => $user->gems,
            'points'  => round($user->points, 4),
        ]);
    }
}
