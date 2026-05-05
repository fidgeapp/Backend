<?php

namespace App\Http\Controllers;

use App\Models\AuthToken;
use App\Models\Otp;
use App\Models\User;
use App\Models\Skin;
use App\Models\Quest;
use App\Models\LeaderboardEntry;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

// BREVO SDK IMPORTS
use Brevo\Client\Api\TransactionalEmailsApi;
use Brevo\Client\Configuration;
use Brevo\Client\Model\SendSmtpEmail;
use GuzzleHttp\Client;

class AuthController extends Controller
{
    // ── OTP helpers ───────────────────────────────────────────────────────────

    private function issueOtp(string $email, string $purpose): string
    {
        Otp::where('email', $email)->where('purpose', $purpose)->where('used', false)->delete();

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        Otp::create([
            'email'      => strtolower($email),
            'code'       => $code,
            'purpose'    => $purpose,
            'used'       => false,
            'expires_at' => now()->addMinutes(10),
        ]);

        return $code;
    }

    /**
     * Sends the OTP via Brevo API
     */
    private function sendOtpEmail(string $email, string $code, string $purpose): void
    {
        $subject = 'Verify your Fidge account';
        $body    = "Welcome to Fidge!\n\nYour verification code is: {$code}\n\nThis code expires in 10 minutes.";

        try {
            // Using absolute namespacing to prevent "Class not found" errors on production
            $config = \Brevo\Client\Configuration::getDefaultConfiguration()
                ->setApiKey('api-key', env('BREVO_API_KEY'));

            $apiInstance = new \Brevo\Client\Api\TransactionalEmailsApi(
                new \GuzzleHttp\Client(), 
                $config
            );

            $sendSmtpEmail = new \Brevo\Client\Model\SendSmtpEmail([
                'subject' => $subject,
                'sender'  => [
                    'name'  => 'Fidge App', 
                    'email' => 'chikaemmanuel1218@gmail.com' 
                ],
                'to'      => [['email' => $email]],
                'textContent' => $body,
            ]);

            $apiInstance->sendTransacEmail($sendSmtpEmail);
            
        } catch (\Exception $e) {
            Log::error('Brevo Email Failed: ' . $e->getMessage());
            
            if ($e instanceof \Brevo\Client\ApiException) {
                Log::error('Brevo API Error Detail: ' . $e->getResponseBody());
            }

            throw new \Exception("Could not send verification email. Please check back later.");
        }
    }

    // ── Register: step 1 — send OTP ──────────────────────────────────────────

    public function registerSendOtp(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => ['required', 'email', 'max:255'],
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[a-zA-Z0-9_]+$/'],
            'password' => ['required', 'string', 'min:8'],
            'ref_code' => ['sometimes', 'nullable', 'string'],
        ]);

        $email    = strtolower(trim($request->email));
        $username = trim($request->username);

        if (User::where('email', $email)->exists()) {
            return response()->json(['error' => 'Email already registered'], 422);
        }
        if (User::where('username', $username)->exists()) {
            return response()->json(['error' => 'Username already taken'], 422);
        }

        try {
            $code = $this->issueOtp($email, 'verify');
            $this->sendOtpEmail($email, $code, 'verify');
            
            return response()->json(['message' => 'Verification code sent to your email.']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // ── Register: step 2 — verify OTP and create account ─────────────────────

    public function registerVerify(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => ['required', 'email'],
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[a-zA-Z0-9_]+$/'],
            'password' => ['required', 'string', 'min:8'],
            'otp'      => ['required', 'string', 'size:6'],
            'ref_code' => ['sometimes', 'nullable', 'string'],
        ]);

        $email    = strtolower(trim($request->email));
        $username = trim($request->username);

        $otp = Otp::where('email', $email)
                   ->where('code', $request->otp)
                   ->where('purpose', 'verify')
                   ->where('used', false)
                   ->first();

        if (!$otp || !$otp->isValid()) {
            return response()->json(['error' => 'Invalid or expired code'], 422);
        }

        if (User::where('email', $email)->exists()) {
            return response()->json(['error' => 'Email already registered'], 422);
        }
        if (User::where('username', $username)->exists()) {
            return response()->json(['error' => 'Username already taken'], 422);
        }

        DB::beginTransaction();
        try {
            $otp->update(['used' => true]);

            $user = User::create([
                'email'          => $email,
                'username'       => $username,
                'password'       => Hash::make($request->password),
                'avatar_color'   => User::usernameToColor($username),
                'referral_code'  => User::generateReferralCode($email),
                'gems'           => 0,
                'active_skin'    => 'Obsidian',
                'email_verified' => true,
            ]);

            // Only Obsidian is free — given at registration
            $obsidian = Skin::where('name', 'Obsidian')->first();
            if ($obsidian) {
                $user->skins()->syncWithoutDetaching([$obsidian->id => ['source' => 'default']]);
            }

            if ($request->ref_code) {
                $referrer = User::where('referral_code', strtoupper($request->ref_code))->first();
                if ($referrer && $referrer->id !== $user->id) {
                    $user->update(['referred_by' => $referrer->id]);
                    $referrer->increment('referral_count');
                    $this->checkReferralMilestones($referrer);
                    $this->checkReferralQuest($referrer);
                    LeaderboardEntry::syncReferrals($referrer);
                }
            }

            $questIds = Quest::where('active', true)->pluck('id');
            foreach ($questIds as $qid) {
                $user->quests()->syncWithoutDetaching([$qid => ['completed' => false]]);
            }

            DB::commit();

            $token  = AuthToken::issue($user);
            $energy = $user->getTodayEnergy();

            return response()->json([
                'token' => $token,
                'user'  => $this->formatUser($user, $energy),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Register failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Registration failed'], 500);
        }
    }

    // ── Login, Logout, Me & Helpers ──────────────────────────────────────────

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $email = strtolower(trim($request->email));
        $user  = User::where('email', $email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['error' => 'Invalid email or password'], 401);
        }

        if ($user->is_banned) {
            return response()->json(['error' => 'Account suspended'], 403);
        }

        $token  = AuthToken::issue($user);
        $energy = $user->getTodayEnergy();

        return response()->json([
            'token' => $token,
            'user'  => $this->formatUser($user, $energy),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->header('X-Auth-Token');
        if ($token) {
            AuthToken::where('token', $token)->delete();
        }
        return response()->json(['message' => 'Logged out']);
    }

    public function me(Request $request): JsonResponse
    {
        $user   = $request->user();
        $energy = $user->getTodayEnergy();
        return response()->json(['user' => $this->formatUser($user, $energy)]);
    }

    private function checkReferralMilestones(User $referrer): void
    {
        $milestones = [10 => 1, 15 => 3, 30 => 9, 50 => 15, 100 => 50];
        foreach ($milestones as $threshold => $gems) {
            if ($referrer->referral_count === $threshold) {
                $referrer->increment('gems', $gems);
                break;
            }
        }
    }

    private function checkReferralQuest(User $referrer): void
    {
        $quest = Quest::where('type', 'collector')->where('title', 'like', '%Referral%')->first()
               ?? Quest::where('title', 'First Referral')->first();
        if (!$quest) return;

        $pivot = $referrer->quests()->where('quest_id', $quest->id)->first();
        if ($pivot && !$pivot->pivot->completed) {
            $referrer->quests()->updateExistingPivot($quest->id, [
                'completed'    => true,
                'completed_at' => now(),
            ]);
            if ($quest->reward_points > 0) {
                $referrer->increment('points', $quest->reward_points);
                $referrer->increment('quest_points', $quest->reward_points);
                LeaderboardEntry::addPoints($referrer, $quest->reward_points);
            }
        }
    }

    private function getSkinMultiplier(string $skinName): float
    {
        return match ($skinName) {
            'Gold'     => 1.3,
            'Sapphire' => 1.4,
            'Neon'     => 1.6,
            'Plasma'   => 1.7,
            default    => 1.0,
        };
    }

    private function formatUser(User $user, $energySession): array
    {
        // Compute remaining ad cooldown seconds server-side
        $cooldownSeconds = 0;
        if ($energySession->ads_watched >= 5 && $energySession->last_ad_at) {
            $elapsed = \Carbon\Carbon::parse($energySession->last_ad_at)->diffInSeconds(now());
            $cooldownSeconds = max(0, 7200 - (int) $elapsed);
        }

        return [
            'id'             => $user->id,
            'email'          => $user->email,
            'username'       => $user->username,
            'avatar_color'   => $user->avatar_color,
            'points'         => round($user->points, 4),
            'spin_points'    => round($user->spin_points, 4),
            'quest_points'   => round($user->quest_points, 4),
            'pcedo_earned'   => round($user->pcedo_earned, 4),
            'gems'           => $user->gems,
            'referral_code'  => $user->referral_code,
            'referral_count' => $user->referral_count,
            'active_skin'    => $user->active_skin,
            'skin_multiplier'=> $this->getSkinMultiplier($user->active_skin ?? 'Obsidian'),
            'email_verified' => $user->email_verified,
            'energy'         => round($energySession->energy, 2),
            'ads_watched'    => $energySession->ads_watched,
            'cooldown_seconds' => $cooldownSeconds,
        ];
    }
}