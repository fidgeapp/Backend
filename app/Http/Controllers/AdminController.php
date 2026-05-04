<?php

namespace App\Http\Controllers;

use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\GemPurchaseRequest;
use App\Models\User;
use App\Models\SpinLog;
use App\Models\WheelSpin;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

// BREVO SDK IMPORTS
use Brevo\Client\Api\TransactionalEmailsApi;
use Brevo\Client\Configuration;
use Brevo\Client\Model\SendSmtpEmail;
use GuzzleHttp\Client as GuzzleClient;

class AdminController extends Controller
{
    private function validateAdminCredentials(string $username, string $password): bool
    {
        $accounts = [
            env('ADMIN_USERNAME',   'admin')    => env('ADMIN_PASSWORD_HASH', env('ADMIN_PASSWORD',   '')),
            env('ADMIN_USERNAME_2', 'fidge_op') => env('ADMIN_PASSWORD_HASH_2', env('ADMIN_PASSWORD_2', '')),
        ];

        if (!isset($accounts[$username]) || empty($accounts[$username])) {
            return false;
        }

        $stored = $accounts[$username];

        if (str_starts_with($stored, '$2y$') || str_starts_with($stored, '$2b$')) {
            return Hash::check($password, $stored);
        }

        return hash_equals($stored, $password);
    }

    /**
     * POST /api/admin/login
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'username'   => ['required', 'string'],
            'password'   => ['required', 'string'],
        ]);

        // Verify credentials using validateAdminCredentials (supports both admins + bcrypt hashes)
        if (!$this->validateAdminCredentials($request->username, $request->password)) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        // Step 1: If OTP + TOTP not yet provided -> send OTP email and ask for both
        if (!$request->filled('otp') || !$request->filled('totp_code')) {
            $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            cache()->put('admin_otp', $otp, now()->addMinutes(10));

            $adminEmail = env('ADMIN_EMAIL', 'fidgeappofficial@gmail.com');

            try {
                $this->sendOtpEmail($adminEmail, $otp, 'Admin Login Verification');
            } catch (\Exception $e) {
                Log::error("Admin OTP Send Failed: " . $e->getMessage());

                return response()->json([
                    'message' => 'Could not send login code. Please check server logs.',
                ], 500);
            }

            return response()->json([
                'requires_2fa' => true,
                'message'      => 'OTP sent to admin email. Enter OTP + your Google Authenticator code.',
            ], 202);
        }

        // Step 2: Verify OTP
        $storedOtp = cache()->get('admin_otp');
        if (!$storedOtp || $request->otp !== $storedOtp) {
            return response()->json(['error' => 'Invalid or expired OTP'], 401);
        }

        // Step 3: Verify TOTP (Google Authenticator)
        $totpSecret = env('ADMIN_TOTP_SECRET');
        if ($totpSecret) {
            try {
                $google2fa = new \PragmaRX\Google2FA\Google2FA();
                $valid = $google2fa->verifyKey($totpSecret, $request->totp_code, 1);
                if (!$valid) {
                    return response()->json(['error' => 'Invalid Google Authenticator code'], 401);
                }
            } catch (\Throwable $e) {
                Log::error('TOTP error', ['e' => $e->getMessage()]);
                return response()->json(['error' => 'Authenticator verification failed'], 500);
            }
        }

        // All checks passed — issue token in the format AdminAuth middleware expects
        cache()->forget('admin_otp');

        $date    = now()->toDateString();                // e.g. "2025-05-01"
        $exp     = now()->addHours(8)->timestamp;        // 8-hour session
        $sig     = hash_hmac('sha256', $request->username . $date, config('app.key'));

        $payload = base64_encode(json_encode([
            'u'    => $request->username,
            'exp'  => $exp,
            'date' => $date,
            'sig'  => $sig,
        ]));

        return response()->json(['token' => $payload, 'message' => 'Logged in']);
    }

    public function setupTotp(Request $request): JsonResponse
    {
        $secret = env('ADMIN_TOTP_SECRET');
        if (!$secret) {
            $google2fa = new \PragmaRX\Google2FA\Google2FA();
            $secret = $google2fa->generateSecretKey();
            return response()->json([
                'message' => 'New TOTP secret generated. Add ADMIN_TOTP_SECRET to your env and scan the QR code.',
                'secret'  => $secret,
                'qr_url'  => "otpauth://totp/FidgeAdmin?secret={$secret}&issuer=Fidge",
            ]);
        }

        $google2fa = new \PragmaRX\Google2FA\Google2FA();
        $qrUrl = $google2fa->getQRCodeUrl('Fidge', 'admin@fidge.app', $secret);
        return response()->json(['qr_url' => $qrUrl, 'secret' => $secret]);
    }

    public function stats(): JsonResponse
    {
        return response()->json([
            'total_users'       => User::count(),
            'active_today'      => SpinLog::whereDate('spin_date', today())->distinct('user_id')->count(),
            'total_spins'       => SpinLog::count(),
            'total_wheel_spins' => WheelSpin::count(),
            'total_coupons'     => Coupon::count(),
            'active_coupons'    => Coupon::where('active', true)->count(),
        ]);
    }

    public function coupons(Request $request): JsonResponse
    {
        $query = Coupon::query();

        if ($request->filled('search')) {
            $query->where('code', 'like', '%' . strtoupper($request->search) . '%')
                  ->orWhere('created_by', 'like', '%' . $request->search . '%');
        }

        if ($request->filter === 'active') {
            $query->where('active', true)->where(function ($q) {
                $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', today());
            })->whereRaw('(max_uses = 0 OR used_count < max_uses)');
        } elseif ($request->filter === 'inactive') {
            $query->where(function ($q) {
                $q->where('active', false)
                  ->orWhere(fn ($q2) => $q2->whereNotNull('expiry_date')->where('expiry_date', '<', today()))
                  ->orWhereRaw('(max_uses > 0 AND used_count >= max_uses)');
            });
        }

        $coupons = $query->orderByDesc('created_at')->get()->map(fn ($c) => [
            'id'           => $c->id,
            'code'         => $c->code,
            'type'         => $c->type,
            'value'        => $c->value,
            'max_uses'     => $c->max_uses,
            'used_count'   => $c->used_count,
            'expiry_date'  => $c->expiry_date?->toDateString(),
            'active'       => $c->active,
            'created_by'   => $c->created_by,
            'created_at'   => $c->created_at->toDateTimeString(),
            'is_expired'   => $c->expiry_date && $c->expiry_date->isPast(),
            'is_exhausted' => $c->max_uses > 0 && $c->used_count >= $c->max_uses,
        ]);

        return response()->json(['coupons' => $coupons]);
    }

    public function createCoupon(Request $request): JsonResponse
    {
        $request->validate([
            'code'        => ['required', 'string', 'max:30', 'unique:coupons,code'],
            'type'        => ['required', 'in:gems,points'],
            'value'       => ['required', 'numeric', 'min:1'],
            'max_uses'    => ['required', 'integer', 'min:0'],
            'expiry_date' => ['nullable', 'date', 'after:today'],
            'created_by'  => ['required', 'string', 'max:50'],
        ]);

        $coupon = Coupon::create([
            'code'        => strtoupper($request->code),
            'type'        => $request->type,
            'value'       => $request->value,
            'max_uses'    => $request->max_uses,
            'used_count'  => 0,
            'expiry_date' => $request->expiry_date,
            'active'      => true,
            'created_by'  => $request->created_by,
        ]);

        return response()->json(['coupon' => $coupon], 201);
    }

    public function toggleCoupon(int $id): JsonResponse
    {
        $coupon = Coupon::findOrFail($id);
        $coupon->update(['active' => !$coupon->active]);
        return response()->json(['active' => $coupon->active, 'code' => $coupon->code]);
    }

    public function deleteCoupon(int $id): JsonResponse
    {
        Coupon::findOrFail($id)->delete();
        return response()->json(['message' => 'Deleted']);
    }

    public function users(Request $request): JsonResponse
    {
        $users = User::query()
            ->when($request->filled('search'), fn ($q) =>
                $q->where('username', 'like', '%' . $request->search . '%')
                  ->orWhere('email', 'like', '%' . $request->search . '%')
            )
            ->orderByDesc('points')
            ->paginate(50)
            ->through(fn ($u) => [
                'id'             => $u->id,
                'username'       => $u->username,
                'email'          => $u->email,
                'points'         => round($u->points, 2),
                'gems'           => $u->gems,
                'referral_count' => $u->referral_count,
                'is_banned'      => $u->is_banned,
                'created_at'     => $u->created_at->toDateString(),
            ]);

        return response()->json($users);
    }

    public function banUser(int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $user->update(['is_banned' => !$user->is_banned]);
        return response()->json(['is_banned' => $user->is_banned]);
    }

    public function gemRequests(Request $request): JsonResponse
    {
        $status = $request->query('status', 'submitted');
        $requests = GemPurchaseRequest::with('user:id,username,email')
            ->where('status', $status)
            ->orderByDesc('submitted_at')
            ->get()
            ->map(fn ($r) => [
                'id'           => $r->id,
                'username'     => $r->user->username ?? '—',
                'email'        => $r->user->email ?? '—',
                'gems'         => $r->gem_amount,
                'eth_amount'   => $r->eth_amount,
                'tx_hash'      => $r->tx_hash,
                'status'       => $r->status,
                'coupon_code'  => $r->coupon_code,
                'submitted_at' => $r->submitted_at?->toISOString(),
                'created_at'   => $r->created_at->toISOString(),
            ]);

        return response()->json(['requests' => $requests]);
    }

    public function verifyGemRequest(Request $request, int $id): JsonResponse
    {
        $req = GemPurchaseRequest::findOrFail($id);

        if ($req->status !== 'submitted') {
            return response()->json(['error' => 'Request is not in submitted state'], 422);
        }

        DB::transaction(function () use ($req) {
            $code = 'GEM-' . strtoupper(Str::random(8));
            Coupon::create([
                'code'       => $code,
                'type'       => 'gems',
                'value'      => $req->gem_amount,
                'max_uses'   => 1,
                'used_count' => 0,
                'active'     => true,
                'created_by' => 'admin',
            ]);

            $req->update([
                'status'      => 'verified',
                'coupon_code' => $code,
                'verified_at' => now(),
            ]);
        });

        return response()->json([
            'message'     => 'Verified. Coupon code issued.',
            'coupon_code' => $req->coupon_code,
        ]);
    }

    public function rejectGemRequest(Request $request, int $id): JsonResponse
    {
        $req = GemPurchaseRequest::findOrFail($id);
        if (!in_array($req->status, ['submitted', 'pending'])) {
            return response()->json(['error' => 'Cannot reject this request'], 422);
        }
        $req->update(['status' => 'rejected']);
        return response()->json(['message' => 'Request rejected.']);
    }

    public function withdrawals(Request $request): JsonResponse
    {
        $status = $request->query('status', 'pending');
        $rows = DB::table('pcedo_withdrawals')
            ->join('users', 'users.id', '=', 'pcedo_withdrawals.user_id')
            ->select(
                'pcedo_withdrawals.id',
                'pcedo_withdrawals.amount',
                'pcedo_withdrawals.wallet_address',
                'pcedo_withdrawals.status',
                'pcedo_withdrawals.created_at',
                'pcedo_withdrawals.processed_at',
                'users.username',
                'users.email'
            )
            ->where('pcedo_withdrawals.status', $status)
            ->orderByDesc('pcedo_withdrawals.created_at')
            ->get();

        return response()->json(['withdrawals' => $rows]);
    }

    public function confirmWithdrawal(Request $request, int $id): JsonResponse
    {
        $updated = DB::table('pcedo_withdrawals')
            ->where('id', $id)
            ->where('status', 'pending')
            ->update([
                'status'       => 'processed',
                'processed_at' => now(),
                'updated_at'   => now(),
            ]);

        if (!$updated) {
            return response()->json(['error' => 'Withdrawal not found or already processed'], 404);
        }

        return response()->json(['message' => 'Withdrawal marked as processed.']);
    }

    public function deleteWithdrawal(Request $request, int $id): JsonResponse
    {
        $row = DB::table('pcedo_withdrawals')->where('id', $id)->first();
        if (!$row) {
            return response()->json(['error' => 'Not found'], 404);
        }

        DB::transaction(function () use ($row) {
            if ($row->status === 'pending') {
                DB::table('users')
                    ->where('id', $row->user_id)
                    ->increment('pcedo_earned', $row->amount);
            }
            DB::table('pcedo_withdrawals')->where('id', $row->id)->delete();
        });

        return response()->json(['message' => 'Withdrawal deleted.']);
    }

    /**
     * PRIVATE METHOD: Sends the OTP via Brevo API
     */
    private function sendOtpEmail(string $email, string $code, string $purpose): void
    {
        $subject = 'Fidge Admin Verification';
        $body    = "Security Alert: Someone is attempting to log into the Fidge Admin Panel.\n\nYour verification code is: {$code}\n\nThis code expires in 10 minutes.";

        try {
            $config = Configuration::getDefaultConfiguration()
                ->setApiKey('api-key', env('BREVO_API_KEY'));

            $apiInstance = new TransactionalEmailsApi(
                new GuzzleClient(),
                $config
            );

            $sendSmtpEmail = new SendSmtpEmail([
                'subject'     => $subject,
                'sender'      => [
                    'name'  => 'Fidge App',
                    'email' => 'chikaemmanuel1218@gmail.com',
                ],
                'to'          => [['email' => $email]],
                'textContent' => $body,
            ]);

            $apiInstance->sendTransacEmail($sendSmtpEmail);

        } catch (\Exception $e) {
            Log::error('Brevo Email Failed: ' . $e->getMessage());

            if ($e instanceof \Brevo\Client\ApiException) {
                Log::error('Brevo API Error Detail: ' . $e->getResponseBody());
            }

            throw new \Exception("Could not send verification email. Please check server logs.");
        }
    }
}
