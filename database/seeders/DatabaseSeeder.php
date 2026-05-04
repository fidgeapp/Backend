<?php

namespace Database\Seeders;

use App\Models\LeaderboardCycle;
use App\Models\LeaderboardEntry;
use App\Models\Quest;
use App\Models\Skin;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ── Waitlist users (from testers_rows_fox.csv) ────────────────────────
        $this->call(WaitlistSeeder::class);

        // ── Skins ─────────────────────────────────────────────────────────────
        $skins = [
            ['name' => 'Obsidian',  'rarity' => 'common',    'gem_cost' => 0,   'shade' => '#1a1a1a', 'image_url' => '/skins/Fidger.png',      'active' => true, 'is_default' => true],
            ['name' => 'Chrome',    'rarity' => 'common',    'gem_cost' => 30,  'shade' => '#c0c0c0', 'image_url' => '/skins/Based.png',       'active' => true, 'is_default' => false],
            ['name' => 'Gold',      'rarity' => 'rare',      'gem_cost' => 50,  'shade' => '#ffd700', 'image_url' => '/skins/EarlyFidger.png', 'active' => true],
            ['name' => 'Sapphire',  'rarity' => 'rare',      'gem_cost' => 75,  'shade' => '#0f52ba', 'image_url' => '/skins/Christmas.png',   'active' => true],
            ['name' => 'Plasma',    'rarity' => 'epic',      'gem_cost' => 100, 'shade' => '#8a2be2', 'image_url' => '/skins/Galaxy.png',      'active' => true],
            ['name' => 'Neon',      'rarity' => 'epic',      'gem_cost' => 80,  'shade' => '#39ff14', 'image_url' => '/skins/Pizza.png',       'active' => true],
        ];

        foreach ($skins as $skin) {
            Skin::updateOrCreate(['name' => $skin['name']], $skin);
        }

        // ── Quests ────────────────────────────────────────────────────────────
        $quests = [
            ['title' => 'First Spin',      'description' => 'Spin the fidget spinner for the first time.', 'type' => 'first_spin',   'reward_points' => 10,  'active' => true],
            ['title' => 'Spin 10 Times',   'description' => 'Spin the fidget spinner 10 times.',           'type' => 'marathon',     'reward_points' => 25,  'active' => true],
            ['title' => 'Spin 50 Times',   'description' => 'Spin the fidget spinner 50 times.',           'type' => 'marathon',     'reward_points' => 75,  'active' => true],
            ['title' => 'Earn 100 Points', 'description' => 'Earn 100 points from spinning.',              'type' => 'daily_grind',  'reward_points' => 20,  'active' => true],
            ['title' => 'First Referral',  'description' => 'Refer your first friend to Fidge.',           'type' => 'collector',    'reward_points' => 50,  'active' => true],
            ['title' => 'Wheel Spin',      'description' => 'Spin the lucky wheel for the first time.',    'type' => 'first_spin',   'reward_points' => 10,  'active' => true],
            ['title' => 'Watch an Ad',     'description' => 'Watch an ad to restore energy.',              'type' => 'energy_master','reward_points' => 5,   'active' => true],
            ['title' => 'Speed Demon',     'description' => 'Spin at max speed.',                          'type' => 'speed_demon',  'reward_points' => 15,  'active' => true],
        ];

        foreach ($quests as $quest) {
            Quest::firstOrCreate(['title' => $quest['title']], $quest);
        }

        // ── Leaderboard cycle (auto-creates via ::current()) ──────────────────
        $cycle = LeaderboardCycle::current();

        // ── Demo users for leaderboard ────────────────────────────────────────
        $demos = [
            ['username' => 'SpinMaster',  'email' => 'spinmaster@demo.fidge',  'points' => 4850, 'spin_points' => 4850, 'gems' => 48, 'referral_count' => 5,  'color' => '#e2e2e2'],
            ['username' => 'NeonRider',   'email' => 'neonrider@demo.fidge',   'points' => 3920, 'spin_points' => 3920, 'gems' => 39, 'referral_count' => 3,  'color' => '#c0c0c0'],
            ['username' => 'VoidWalker',  'email' => 'voidwalker@demo.fidge',  'points' => 3410, 'spin_points' => 3410, 'gems' => 34, 'referral_count' => 8,  'color' => '#a8a8a8'],
            ['username' => 'GoldFinger',  'email' => 'goldfinger@demo.fidge',  'points' => 2780, 'spin_points' => 2780, 'gems' => 27, 'referral_count' => 2,  'color' => '#909090'],
            ['username' => 'CryptoSpin',  'email' => 'cryptospin@demo.fidge',  'points' => 2340, 'spin_points' => 2340, 'gems' => 23, 'referral_count' => 12, 'color' => '#787878'],
            ['username' => 'DiamondHand', 'email' => 'diamondhand@demo.fidge', 'points' => 1870, 'spin_points' => 1870, 'gems' => 18, 'referral_count' => 4,  'color' => '#606060'],
            ['username' => 'PlasmaKing',  'email' => 'plasmaking@demo.fidge',  'points' => 1230, 'spin_points' => 1230, 'gems' => 12, 'referral_count' => 1,  'color' => '#e2e2e2'],
            ['username' => 'ChronoSpin',  'email' => 'chronospin@demo.fidge',  'points' => 680,  'spin_points' => 680,  'gems' => 6,  'referral_count' => 0,  'color' => '#c0c0c0'],
        ];

        $defaultSkinIds = Skin::whereIn('name', ['Obsidian', 'Chrome'])->pluck('id');
        $questIds = Quest::where('active', true)->pluck('id');

        foreach ($demos as $data) {
            $user = User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'username'       => $data['username'],
                    'password'       => Hash::make('Demo@1234!'),
                    'avatar_color'   => $data['color'],
                    'referral_code'  => strtoupper(substr(md5($data['email']), 0, 8)),
                    'gems'           => $data['gems'],
                    'active_skin'    => 'Obsidian',
                    'email_verified' => true,
                    'points'         => $data['points'],
                    'spin_points'    => $data['spin_points'],
                    'referral_count' => $data['referral_count'],
                ]
            );

            foreach ($defaultSkinIds as $skinId) {
                $user->skins()->syncWithoutDetaching([$skinId => ['source' => 'default']]);
            }
            foreach ($questIds as $qid) {
                $user->quests()->syncWithoutDetaching([$qid => ['completed' => false]]);
            }

            // Add leaderboard entry
            LeaderboardEntry::updateOrCreate(
                ['cycle_id' => $cycle->id, 'user_id' => $user->id],
                ['points' => $data['points'], 'referral_count' => $data['referral_count']]
            );
        }
    }
}
