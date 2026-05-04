<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Hash;

class User extends Authenticatable
{
    use HasFactory;

    protected $fillable = [
        'email',
        'username',
        'password',
        'avatar_color',
        'points',
        'spin_points',
        'quest_points',
        'pcedo_earned',
        'gems',
        'referral_code',
        'referred_by',
        'referral_count',
        'active_skin',
        'is_banned',
        'email_verified',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'points'         => 'float',
        'spin_points'    => 'float',
        'quest_points'   => 'float',
        'pcedo_earned'   => 'float',
        'gems'           => 'integer',
        'referral_count' => 'integer',
        'is_banned'      => 'boolean',
        'email_verified' => 'boolean',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function energySession()
    {
        return $this->hasOne(EnergySession::class)->where('session_date', today()->toDateString());
    }

    public function spinLogs()   { return $this->hasMany(SpinLog::class); }
    public function wheelSpins() { return $this->hasMany(WheelSpin::class); }

    public function skins()
    {
        return $this->belongsToMany(Skin::class, 'user_skins')->withPivot('source')->withTimestamps();
    }

    public function quests()
    {
        return $this->belongsToMany(Quest::class, 'user_quests')
            ->withPivot('completed', 'completed_at')
            ->withTimestamps();
    }

    public function referrer()  { return $this->belongsTo(User::class, 'referred_by'); }
    public function referrals() { return $this->hasMany(User::class, 'referred_by'); }
    public function couponRedemptions() { return $this->hasMany(CouponRedemption::class); }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public static function generateReferralCode(string $email): string
    {
        return strtoupper(substr(md5($email), 0, 8));
    }

    public static function usernameToColor(string $username): string
    {
        $colors = ['#e2e2e2', '#c0c0c0', '#a8a8a8', '#909090', '#787878', '#606060'];
        $idx    = ord($username[0]) % count($colors);
        return $colors[$idx];
    }

    public function getTodayEnergy(): EnergySession
    {
        return EnergySession::firstOrCreate(
            ['user_id' => $this->id, 'session_date' => today()->toDateString()],
            ['energy' => 100, 'ads_watched' => 0]
        );
    }
}
