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
use Illuminate\Support\Facades\Route;

// ── OPTIONS preflight ─────────────────────────────────────────────────────────
Route::options('{any}', fn() => response()->json([], 200))->where('any', '.*');

// ── Health check ──────────────────────────────────────────────────────────────
Route::get('/health', fn () => response()->json([
    'status'    => 'ok',
    'timestamp' => now()->toISOString(),
]));

// ── Auth (public) ─────────────────────────────────────────────────────────────
Route::prefix('auth')->group(function () {
    // Register flow
    Route::post('/register/send-otp',  [AuthController::class, 'registerSendOtp']);
    Route::post('/register/verify',    [AuthController::class, 'registerVerify']);

    // Login (direct — no OTP)
    Route::post('/login',              [AuthController::class, 'login']);

    // Authenticated
    Route::post('/logout',             [AuthController::class, 'logout'])->middleware('session.auth');
    Route::get('/me',                  [AuthController::class, 'me'])->middleware('session.auth');
});

// ── Public endpoints ──────────────────────────────────────────────────────────
Route::get('/leaderboard',       [LeaderboardController::class, 'index']);
Route::get('/wheel/segments',    [WheelController::class, 'segments']);
Route::get('/shop/skins',        [ShopController::class, 'index']);
Route::get('/marketplace/packages', [MarketplaceController::class, 'packages_list']);

// ── Authenticated user endpoints ──────────────────────────────────────────────
Route::middleware(['session.auth', 'ban.check'])->group(function () {

    // Spinner
    Route::prefix('spinner')->group(function () {
        Route::post('/sync',        [SpinnerController::class, 'sync']);
        Route::post('/session-end', [SpinnerController::class, 'sessionEnd']);
        Route::post('/watch-ad',    [SpinnerController::class, 'watchAd']);
    });

    // Wheel
    Route::post('/wheel/spin', [WheelController::class, 'spin']);

    // Profile
    Route::prefix('profile')->group(function () {
        Route::get('/',                [ProfileController::class, 'show']);
        Route::post('/convert-points', [ProfileController::class, 'convertPoints']);
        Route::post('/withdraw-pcedo',          [ProfileController::class, 'withdrawPcedo']);
        Route::get('/withdrawals',                [ProfileController::class, 'myWithdrawals']);
        Route::delete('/withdrawals/{id}',        [ProfileController::class, 'deleteWithdrawal']);
        Route::post('/set-skin',       [ProfileController::class, 'setSkin']);
        Route::post('/quests/{id}/confirm', [ProfileController::class, 'confirmQuest']);
    });

    // Shop
    Route::post('/shop/skins/{id}/purchase', [ShopController::class, 'purchase']);

    // Coupons
    Route::post('/coupons/redeem', [CouponController::class, 'redeem']);

    // Marketplace (gem purchases)
    Route::prefix('marketplace')->group(function () {
        Route::post('/initiate',   [MarketplaceController::class, 'initiate']);
        Route::post('/submit-tx',  [MarketplaceController::class, 'submitTx']);
        Route::get('/status',      [MarketplaceController::class, 'status']);
    });
});

// ── Admin endpoints ───────────────────────────────────────────────────────────
Route::prefix('admin')->middleware('admin.auth')->group(function () {
    Route::post('/login',                [AdminController::class, 'login'])->withoutMiddleware('admin.auth');
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
});
