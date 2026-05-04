<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('quests', function (Blueprint $table) {
            $table->id();
            $table->string('title', 100);
            $table->string('description', 255);
            $table->integer('reward_points')->default(0);
            $table->string('type', 30); // first_spin|speed_demon|marathon|energy_master|collector|daily_grind
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('user_quests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('quest_id');
            $table->boolean('completed')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('quest_id')->references('id')->on('quests')->cascadeOnDelete();
            $table->unique(['user_id', 'quest_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_quests');
        Schema::dropIfExists('quests');
    }
};
