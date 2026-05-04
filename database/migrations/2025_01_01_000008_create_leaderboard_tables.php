<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Tracks per-cycle points so we can reset without losing all-time data
        Schema::create('leaderboard_cycles', function (Blueprint $table) {
            $table->id();
            $table->integer('cycle_number');
            $table->date('cycle_start');
            $table->date('cycle_end');
            $table->timestamps();

            $table->unique('cycle_number');
        });

        Schema::create('leaderboard_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cycle_id');
            $table->unsignedBigInteger('user_id');
            $table->decimal('points', 18, 4)->default(0);
            $table->integer('referral_count')->default(0);
            $table->timestamps();

            $table->foreign('cycle_id')->references('id')->on('leaderboard_cycles')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['cycle_id', 'user_id']);
            $table->index(['cycle_id', 'points']);
            $table->index(['cycle_id', 'referral_count']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leaderboard_entries');
        Schema::dropIfExists('leaderboard_cycles');
    }
};
