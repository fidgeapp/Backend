<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * WaitlistSeeder
 *
 * Seeds waitlist users from testers_rows_fox.csv
 * Each user gets:
 *  - Their waitlist email
 *  - Their assigned passcode as the password (bcrypt hashed)
 *  - A username derived from their email prefix
 *  - email_verified = true  (they were on the waitlist, no OTP needed)
 *  - A unique referral code
 *
 * Run with:
 *   php artisan db:seed --class=WaitlistSeeder
 *
 * Safe to re-run — skips existing emails.
 */
class WaitlistSeeder extends Seeder
{
    // ── Avatar colour pool ────────────────────────────────────────────────────
    private const COLORS = [
        '#FF6B6B', '#FF8E53', '#FFC300', '#A8E063', '#56CCF2',
        '#6C63FF', '#F953C6', '#43E97B', '#FA709A', '#4FACFE',
        '#00F2FE', '#F093FB', '#FD746C', '#02AABD', '#FFB347',
    ];

    public function run(): void
    {
        $csvPath = base_path('database/seeders/waitlist_data/testers_rows_fox.csv');

        if (!file_exists($csvPath)) {
            $this->command->error("CSV not found at: {$csvPath}");
            $this->command->info("Please place testers_rows_fox.csv in database/seeders/waitlist_data/");
            return;
        }

        $handle = fopen($csvPath, 'r');
        if (!$handle) {
            $this->command->error('Could not open CSV file.');
            return;
        }

        // Skip header row
        $header = fgetcsv($handle);
        if (!$header) {
            $this->command->error('CSV appears empty.');
            fclose($handle);
            return;
        }

        $this->command->info('Starting waitlist seeder...');

        $inserted  = 0;
        $skipped   = 0;
        $errors    = 0;
        $usernamesSeen = [];

        // Load existing usernames and emails to avoid conflicts
        $existingEmails    = DB::table('users')->pluck('email')->flip()->toArray();
        $existingUsernames = DB::table('users')->pluck('username')->flip()->toArray();

        $batch = [];
        $batchSize = 100;
        $rowNum = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;
            if (count($row) < 2) continue;

            $email    = strtolower(trim($row[0]));
            $passcode = trim($row[1]);

            if (empty($email) || empty($passcode) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors++;
                continue;
            }

            // Skip if already exists
            if (isset($existingEmails[$email])) {
                $skipped++;
                continue;
            }

            // Generate username from email prefix (alphanumeric, max 20 chars)
            $base     = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', explode('@', $email)[0]));
            $base     = $base ?: 'user';
            $base     = substr($base, 0, 16);
            $username = $base;
            $suffix   = 1;

            while (isset($existingUsernames[$username]) || isset($usernamesSeen[$username])) {
                $username = $base . $suffix;
                $suffix++;
            }

            $usernamesSeen[$username]  = true;
            $existingEmails[$email]    = true;
            $existingUsernames[$username] = true;

            $now = now()->toDateTimeString();

            $batch[] = [
                'email'          => $email,
                'username'       => $username,
                'password'       => Hash::make($passcode),
                'avatar_color'   => self::COLORS[array_rand(self::COLORS)],
                'points'         => 0,
                'spin_points'    => 0,
                'quest_points'   => 0,
                'pcedo_earned'   => 0,
                'gems'           => 0,
                'referral_code'  => strtoupper(Str::random(8)),
                'referred_by'    => null,
                'referral_count' => 0,
                'active_skin'    => 'Obsidian',
                'is_banned'      => false,
                'email_verified' => true,        // Waitlist = pre-verified
                'created_at'     => $now,
                'updated_at'     => $now,
            ];

            if (count($batch) >= $batchSize) {
                try {
                    DB::table('users')->insert($batch);
                    $inserted += count($batch);
                    $this->command->line("  ✓ Inserted {$inserted} users so far...");
                } catch (\Exception $e) {
                    $this->command->warn("  Batch error: " . $e->getMessage());
                    $errors += count($batch);
                }
                $batch = [];
            }
        }

        // Insert remaining batch
        if (!empty($batch)) {
            try {
                DB::table('users')->insert($batch);
                $inserted += count($batch);
            } catch (\Exception $e) {
                $this->command->warn("Final batch error: " . $e->getMessage());
                $errors += count($batch);
            }
        }

        fclose($handle);

        $this->command->newLine();
        $this->command->info("✅ Waitlist seeder complete!");
        $this->command->table(
            ['Result',   'Count'],
            [
                ['Inserted',  $inserted],
                ['Skipped',   $skipped],
                ['Errors',    $errors],
            ]
        );
        $this->command->newLine();
        $this->command->info("Each user can log in with:");
        $this->command->line("  Email    → their waitlist email");
        $this->command->line("  Password → their assigned passcode (e.g. XQE-K4Y)");
    }
}
