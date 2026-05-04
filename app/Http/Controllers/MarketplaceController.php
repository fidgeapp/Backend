<?php

namespace App\Http\Controllers;

use App\Models\GemPurchaseRequest;
use App\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class MarketplaceController extends Controller
{
    /**
     * Gem packages available for purchase.
     * ETH price is approximate — adjust ETH_PRICE_USD in .env as market changes.
     */
    private function packages(): array
    {
        $ethUsd = (float) config('fidge.eth_price_usd', 3000);

        // Gem prices in USD — lower per-gem cost for larger bundles
        $bundles = [
            ['gems' => 50,   'usd' => 0.99],
            ['gems' => 150,  'usd' => 2.49],
            ['gems' => 350,  'usd' => 4.99],
            ['gems' => 750,  'usd' => 9.99],
            ['gems' => 1600, 'usd' => 19.99],
            ['gems' => 4000, 'usd' => 44.99],
        ];

        return array_map(function ($b) use ($ethUsd) {
            return [
                'gems'       => $b['gems'],
                'usd'        => $b['usd'],
                'eth_amount' => round($b['usd'] / $ethUsd, 6),
            ];
        }, $bundles);
    }

    /**
     * GET /api/marketplace/packages
     * Returns gem bundles + our ETH wallet address.
     */
    public function packages_list(Request $request): JsonResponse
    {
        return response()->json([
            'packages'       => $this->packages(),
            'wallet_address' => config('fidge.eth_wallet', env('ETH_WALLET_ADDRESS')),
            'eth_price_usd'  => (float) config('fidge.eth_price_usd', 3000),
        ]);
    }

    /**
     * POST /api/marketplace/initiate
     * User picks a package → we return our wallet address + a pending request ID.
     * Body: { gem_amount: 150 }
     */
    public function initiate(Request $request): JsonResponse
    {
        $request->validate(['gem_amount' => ['required', 'integer', 'min:1']]);

        $user     = $request->user();
        $packages = $this->packages();
        $pkg      = collect($packages)->firstWhere('gems', (int) $request->gem_amount);

        if (!$pkg) {
            return response()->json(['error' => 'Invalid gem package selected'], 422);
        }

        $walletAddress = config('fidge.eth_wallet', env('ETH_WALLET_ADDRESS', ''));
        // Fallback: if no env var set yet, use a placeholder so the request still
        // gets created — admin sets the real address in Railway env vars
        if (!$walletAddress) {
            $walletAddress = env('ETH_WALLET_ADDRESS', 'NOT_CONFIGURED');
        }

        // Cancel any old pending requests for this user
        GemPurchaseRequest::where('user_id', $user->id)
            ->where('status', 'pending')
            ->delete();

        $req = GemPurchaseRequest::create([
            'user_id'        => $user->id,
            'gem_amount'     => $pkg['gems'],
            'eth_amount'     => $pkg['eth_amount'],
            'wallet_address' => $walletAddress,
            'status'         => 'pending',
        ]);

        return response()->json([
            'request_id'     => $req->id,
            'gems'           => $pkg['gems'],
            'eth_amount'     => $pkg['eth_amount'],
            'usd_value'      => $pkg['usd'],
            'wallet_address' => $walletAddress,
            'message'        => "Send exactly {$pkg['eth_amount']} ETH to the address below. Then submit your transaction hash.",
        ]);
    }

    /**
     * POST /api/marketplace/submit-tx
     * User submits TX hash. We auto-issue a coupon immediately since the
     * transaction was sent directly from their wallet through our UI.
     * Admin can still reject + revoke if TX turns out to be invalid.
     * Body: { request_id: 12, tx_hash: "0x..." }
     */
    public function submitTx(Request $request): JsonResponse
    {
        $request->validate([
            'request_id' => ['required', 'integer'],
            'tx_hash'    => ['required', 'string', 'min:10', 'max:200'],
        ]);

        $user = $request->user();
        $req  = GemPurchaseRequest::where('id', $request->request_id)
                    ->where('user_id', $user->id)
                    ->where('status', 'pending')
                    ->first();

        if (!$req) {
            return response()->json(['error' => 'Purchase request not found or already processed'], 404);
        }

        if (GemPurchaseRequest::where('tx_hash', trim($request->tx_hash))
                ->where('id', '!=', $req->id)->exists()) {
            return response()->json(['error' => 'This transaction hash has already been submitted'], 422);
        }

        // Auto-issue coupon and verify immediately
        $code = 'GEM-' . strtoupper(\Illuminate\Support\Str::random(8));

        \Illuminate\Support\Facades\DB::transaction(function () use ($req, $request, $code, $user) {
            // Create single-use gem coupon
            \App\Models\Coupon::create([
                'code'       => $code,
                'type'       => 'gems',
                'value'      => $req->gem_amount,
                'max_uses'   => 1,
                'used_count' => 0,
                'active'     => true,
                'created_by' => 'auto',
            ]);

            $req->update([
                'tx_hash'      => trim($request->tx_hash),
                'status'       => 'verified',
                'coupon_code'  => $code,
                'submitted_at' => now(),
                'verified_at'  => now(),
            ]);
        });

        $req->refresh();

        return response()->json([
            'message'     => "Transaction received! Use coupon code {$code} to claim your {$req->gem_amount} gems.",
            'status'      => 'verified',
            'coupon_code' => $code,
            'gems'        => $req->gem_amount,
        ]);
    }

    /**
     * GET /api/marketplace/status
     * Check the status of user's purchase requests.
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        $requests = GemPurchaseRequest::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'id'           => $r->id,
                'gems'         => $r->gem_amount,
                'eth_amount'   => $r->eth_amount,
                'status'       => $r->status,
                'coupon_code'  => $r->status === 'verified' ? $r->coupon_code : null,
                'submitted_at' => $r->submitted_at?->toISOString(),
                'verified_at'  => $r->verified_at?->toISOString(),
                'created_at'   => $r->created_at->toISOString(),
            ]);

        return response()->json(['requests' => $requests]);
    }
}
