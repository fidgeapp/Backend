<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('energy_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->date('session_date');
            $table->decimal('energy', 6, 2)->default(100);   // 0–100
            $table->integer('ads_watched')->default(0);       // max 5/day
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['user_id', 'session_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('energy_sessions');
    }
};
