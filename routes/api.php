<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\SpinnerController;
use App\Http\Controllers\WheelController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\CouponController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\MarketplaceController;
use App\Http\Controllers\StatsController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;

// ── OPTIONS preflight ─────────────────────────────────────────────────────────
Route::options('{any}', fn() => response()->json([], 200))->where('any', '.*');

// ── Health check ──────────────────────────────────────────────────────────────
Route::get('/health', fn () => response()->json([
    'status'    => 'ok',
    'timestamp' => now()->toISOString(),
]));

// ── Auth (public) — rate limited ──────────────────────────────────────────────
Route::prefix('auth')->group(function () {
    // Register flow — 5 attempts per IP per minute
    Route::post('/register/send-otp',  [AuthController::class, 'registerSendOtp'])->middleware('throttle:5,1');
    Route::post('/register/verify',    [AuthController::class, 'registerVerify'])->middleware('throttle:5,1');

    // Login — 10 attempts per IP per minute (brute-force protection)
    Route::post('/login',              [AuthController::class, 'login'])->middleware('throttle:10,1');

    // Authenticated
    Route::post('/logout',             [AuthController::class, 'logout'])->middleware('session.auth');
    Route::get('/me',                  [AuthController::class, 'me'])->middleware('session.auth');
});

// ── Public analytics (investor/sponsor dashboard) — heavily cached ─────────────
Route::get('/stats', [StatsController::class, 'index'])->middleware('throttle:30,1');

// ── Public endpoints ──────────────────────────────────────────────────────────
Route::get('/leaderboard',          [LeaderboardController::class, 'index'])->middleware('throttle:60,1');
Route::get('/wheel/segments',       [WheelController::class, 'segments']);
Route::get('/shop/skins',           [ShopController::class, 'index']);
Route::get('/marketplace/packages', [MarketplaceController::class, 'packages_list']);

// ── Authenticated user endpoints ──────────────────────────────────────────────
Route::middleware(['session.auth', 'ban.check'])->group(function () {

    // Spinner — rate limits enforced inside controller via Cache
    Route::prefix('spinner')->group(function () {
        Route::post('/sync',        [SpinnerController::class, 'sync']);
        Route::post('/session-end', [SpinnerController::class, 'sessionEnd']);
        Route::post('/watch-ad',    [SpinnerController::class, 'watchAd']);
    });

    // Wheel — rate limited: max 30 spins/minute per user
    Route::post('/wheel/spin',       [WheelController::class, 'spin'])->middleware('throttle:30,1');
    Route::post('/wheel/spin-multi', [WheelController::class, 'spinMulti'])->middleware('throttle:30,1');

    // Profile
    Route::prefix('profile')->group(function () {
        Route::get('/',                    [ProfileController::class, 'show']);
        Route::post('/convert-points',     [ProfileController::class, 'convertPoints'])->middleware('throttle:20,1');
        Route::post('/withdraw-pcedo',     [ProfileController::class, 'withdrawPcedo'])->middleware('throttle:5,1');
        Route::get('/withdrawals',         [ProfileController::class, 'myWithdrawals']);
        Route::delete('/withdrawals/{id}', [ProfileController::class, 'deleteWithdrawal']);
        Route::post('/set-skin',           [ProfileController::class, 'setSkin'])->middleware('throttle:20,1');
        Route::post('/quests/{id}/confirm',[ProfileController::class, 'confirmQuest'])->middleware('throttle:10,1');
    });

    // Shop
    Route::post('/shop/skins/{id}/purchase', [ShopController::class, 'purchase'])->middleware('throttle:10,1');

    // Coupons — 5 attempts/minute (prevents brute-force code guessing)
    Route::post('/coupons/redeem', [CouponController::class, 'redeem'])->middleware('throttle:5,1');

    // Marketplace
    Route::prefix('marketplace')->group(function () {
        Route::post('/initiate',   [MarketplaceController::class, 'initiate'])->middleware('throttle:10,1');
        Route::post('/submit-tx',  [MarketplaceController::class, 'submitTx'])->middleware('throttle:5,1');
        Route::get('/status',      [MarketplaceController::class, 'status']);
    });
});

// ── Admin endpoints ───────────────────────────────────────────────────────────
Route::prefix('admin')->middleware('admin.auth')->group(function () {
    // Login rate limited hard — 5 attempts per IP per minute
    Route::post('/login',                [AdminController::class, 'login'])
        ->withoutMiddleware('admin.auth')
        ->middleware('throttle:5,1');

    Route::get('/stats',                 [AdminController::class, 'stats']);
    Route::get('/coupons',               [AdminController::class, 'coupons']);
    Route::post('/coupons',              [AdminController::class, 'createCoupon']);
    Route::patch('/coupons/{id}/toggle', [AdminController::class, 'toggleCoupon']);
    Route::delete('/coupons/{id}',       [AdminController::class, 'deleteCoupon']);
    Route::get('/users',                 [AdminController::class, 'users']);
    Route::patch('/users/{id}/ban',      [AdminController::class, 'banUser']);
    Route::get('/gem-requests',                  [AdminController::class, 'gemRequests']);
    Route::post('/gem-requests/{id}/verify',     [AdminController::class, 'verifyGemRequest']);
    Route::post('/gem-requests/{id}/reject',     [AdminController::class, 'rejectGemRequest']);
    Route::get('/withdrawals',                       [AdminController::class, 'withdrawals']);
    Route::post('/withdrawals/{id}/confirm',         [AdminController::class, 'confirmWithdrawal']);
    Route::delete('/withdrawals/{id}',               [AdminController::class, 'deleteWithdrawal']);
    Route::post('/leaderboard/resync',               [AdminController::class, 'resyncLeaderboard']);
});
