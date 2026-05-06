<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\SpinLog;
use App\Models\WheelSpin;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\GemPurchaseRequest;
use App\Models\Skin;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

/**
 * StatsController — Public analytics endpoint for investor/sponsor dashboard.
 * No authentication required. Data is cached 5 minutes to protect DB.
 * Accessed via GET /api/stats
 */
class StatsController extends Controller
{
    public function index(): JsonResponse
    {
        $data = Cache::remember('public_stats', 300, function () {

            // ── Core counters ─────────────────────────────────────────────────
            $totalUsers     = User::count();
            $verifiedUsers  = User::where('email_verified', true)->count();
            $bannedUsers    = User::where('is_banned', true)->count();
            $activeToday    = SpinLog::whereDate('spin_date', today())->distinct('user_id')->count();
            $activeWeek     = SpinLog::where('spin_date', '>=', now()->subDays(7))->distinct('user_id')->count();
            $activeMonth    = SpinLog::where('spin_date', '>=', now()->subDays(30))->distinct('user_id')->count();

            // ── Growth — new users per day (last 14 days) ─────────────────────
            $userGrowth = User::select(
                    DB::raw('DATE(created_at) as date'),
                    DB::raw('COUNT(*) as count')
                )
                ->where('created_at', '>=', now()->subDays(13))
                ->groupBy('date')
                ->orderBy('date')
                ->get()
                ->map(fn($r) => ['date' => $r->date, 'count' => (int)$r->count]);

            // ── Spin activity — daily spins last 14 days ──────────────────────
            $spinActivity = SpinLog::select(
                    DB::raw('DATE(spin_date) as date'),
                    DB::raw('COUNT(*) as sessions'),
                    DB::raw('SUM(points_earned) as points'),
                    DB::raw('COUNT(DISTINCT user_id) as spinners')
                )
                ->where('spin_date', '>=', now()->subDays(13))
                ->groupBy('date')
                ->orderBy('date')
                ->get()
                ->map(fn($r) => [
                    'date'     => $r->date,
                    'sessions' => (int)$r->sessions,
                    'points'   => round((float)$r->points, 1),
                    'spinners' => (int)$r->spinners,
                ]);

            // ── Spin totals ───────────────────────────────────────────────────
            $totalSpins       = SpinLog::count();
            $totalPointsSpun  = round(SpinLog::sum('points_earned'), 0);
            $avgSessionPoints = $totalSpins > 0 ? round($totalPointsSpun / $totalSpins, 1) : 0;

            // ── Wheel stats ───────────────────────────────────────────────────
            $totalWheelSpins = WheelSpin::count();
            $totalGemsSpent  = WheelSpin::sum('gems_spent');
            $wheelPrizes     = WheelSpin::select('prize_type', DB::raw('COUNT(*) as count'), DB::raw('SUM(prize_value) as total'))
                ->groupBy('prize_type')
                ->get()
                ->map(fn($r) => ['type' => $r->prize_type, 'count' => (int)$r->count, 'total' => round((float)$r->total, 2)]);

            // ── $PCEDO ────────────────────────────────────────────────────────
            $totalPcedoEarned    = round(User::sum('pcedo_earned'), 2);
            $totalPcedoWithdrawn = round(DB::table('pcedo_withdrawals')->where('status', 'processed')->sum('amount'), 2);
            $pendingWithdrawals  = DB::table('pcedo_withdrawals')->where('status', 'pending')->count();
            $withdrawalVolume    = round(DB::table('pcedo_withdrawals')->where('status', 'processed')->sum('amount'), 2);

            // ── Gem economy ───────────────────────────────────────────────────
            $totalGemsInCirculation = User::sum('gems');
            $totalPointsInSystem    = round(User::sum('points'), 0);
            $gemPurchaseRequests    = GemPurchaseRequest::count();
            $verifiedPurchases      = GemPurchaseRequest::where('status', 'verified')->count();
            $pendingPurchases       = GemPurchaseRequest::where('status', 'submitted')->count();

            // ── Skin distribution ─────────────────────────────────────────────
            $skinDist = DB::table('user_skins')
                ->join('skins', 'skins.id', '=', 'user_skins.skin_id')
                ->select('skins.name', 'skins.rarity', DB::raw('COUNT(*) as owners'))
                ->groupBy('skins.id', 'skins.name', 'skins.rarity')
                ->orderByDesc('owners')
                ->get()
                ->map(fn($r) => ['name' => $r->name, 'rarity' => $r->rarity, 'owners' => (int)$r->owners]);

            // ── Active skin usage ─────────────────────────────────────────────
            $activeSkinUsage = User::select('active_skin', DB::raw('COUNT(*) as count'))
                ->groupBy('active_skin')
                ->orderByDesc('count')
                ->get()
                ->map(fn($r) => ['skin' => $r->active_skin, 'count' => (int)$r->count]);

            // ── Referral stats ────────────────────────────────────────────────
            $totalReferrals      = User::whereNotNull('referred_by')->count();
            $topReferrers        = User::orderByDesc('referral_count')->limit(5)
                ->get(['username', 'referral_count'])
                ->map(fn($u) => ['username' => $u->username, 'referrals' => $u->referral_count]);
            $referralConvRate    = $totalUsers > 0 ? round(($totalReferrals / $totalUsers) * 100, 1) : 0;

            // ── Coupon stats ──────────────────────────────────────────────────
            $totalCoupons    = Coupon::count();
            $activeCoupons   = Coupon::where('active', true)->count();
            $totalRedemptions = CouponRedemption::count();

            // ── Retention (users who spun on 2+ distinct days) ────────────────
            $retainedUsers = SpinLog::select('user_id')
                ->groupBy('user_id')
                ->havingRaw('COUNT(DISTINCT spin_date) >= 2')
                ->get()
                ->count();
            $retentionRate = $totalUsers > 0 ? round(($retainedUsers / $totalUsers) * 100, 1) : 0;

            // ── Top 5 earners (points) ────────────────────────────────────────
            $topEarners = User::orderByDesc('points')->limit(5)
                ->get(['username', 'points', 'active_skin', 'referral_count'])
                ->map(fn($u) => [
                    'username'   => $u->username,
                    'points'     => round($u->points, 0),
                    'skin'       => $u->active_skin,
                    'referrals'  => $u->referral_count,
                ]);

            // ── Hour-of-day activity (all time) ──────────────────────────────
            $hourlyActivity = SpinLog::select(
                    DB::raw('HOUR(created_at) as hour'),
                    DB::raw('COUNT(*) as count')
                )
                ->groupBy('hour')
                ->orderBy('hour')
                ->get()
                ->map(fn($r) => ['hour' => (int)$r->hour, 'count' => (int)$r->count]);

            return [
                'generated_at'         => now()->toISOString(),
                'overview' => [
                    'total_users'          => $totalUsers,
                    'verified_users'       => $verifiedUsers,
                    'banned_users'         => $bannedUsers,
                    'active_today'         => $activeToday,
                    'active_this_week'     => $activeWeek,
                    'active_this_month'    => $activeMonth,
                    'retention_rate'       => $retentionRate,
                    'total_spins'          => $totalSpins,
                    'total_wheel_spins'    => $totalWheelSpins,
                    'total_points_in_system' => $totalPointsInSystem,
                    'total_gems_in_circulation' => $totalGemsInCirculation,
                    'total_pcedo_earned'   => $totalPcedoEarned,
                    'total_pcedo_withdrawn'=> $totalPcedoWithdrawn,
                    'pending_withdrawals'  => $pendingWithdrawals,
                    'referral_rate'        => $referralConvRate,
                    'total_referrals'      => $totalReferrals,
                    'gem_purchase_requests'=> $gemPurchaseRequests,
                    'verified_purchases'   => $verifiedPurchases,
                    'pending_purchases'    => $pendingPurchases,
                    'avg_points_per_session' => $avgSessionPoints,
                    'total_points_spun'    => $totalPointsSpun,
                ],
                'user_growth'    => $userGrowth,
                'spin_activity'  => $spinActivity,
                'wheel_prizes'   => $wheelPrizes,
                'skin_dist'      => $skinDist,
                'active_skin_usage' => $activeSkinUsage,
                'top_earners'    => $topEarners,
                'top_referrers'  => $topReferrers,
                'hourly_activity'=> $hourlyActivity,
                'coupons' => [
                    'total'       => $totalCoupons,
                    'active'      => $activeCoupons,
                    'redemptions' => $totalRedemptions,
                ],
            ];
        });

        return response()->json($data);
    }
}
