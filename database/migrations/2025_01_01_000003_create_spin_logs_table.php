<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('spin_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->decimal('points_earned', 12, 4)->default(0);
            $table->decimal('energy_used', 6, 2)->default(0);
            $table->decimal('duration_seconds', 8, 2)->default(0);
            $table->date('spin_date');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['user_id', 'spin_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spin_logs');
    }
};
