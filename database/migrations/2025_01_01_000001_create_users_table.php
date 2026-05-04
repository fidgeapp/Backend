<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('username', 50)->unique();
            $table->string('password');
            $table->string('avatar_color', 10)->nullable();
            $table->decimal('points', 18, 4)->default(0);
            $table->decimal('spin_points', 18, 4)->default(0);
            $table->decimal('quest_points', 18, 4)->default(0);
            $table->decimal('pcedo_earned', 18, 4)->default(0);
            $table->integer('gems')->default(0);
            $table->string('referral_code', 20)->unique()->nullable();
            $table->unsignedBigInteger('referred_by')->nullable();
            $table->integer('referral_count')->default(0);
            $table->string('active_skin')->default('Obsidian');
            $table->boolean('is_banned')->default(false);
            $table->boolean('email_verified')->default(false);
            $table->timestamps();

            $table->foreign('referred_by')->references('id')->on('users')->nullOnDelete();
            $table->index('points');
            $table->index('referral_count');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
