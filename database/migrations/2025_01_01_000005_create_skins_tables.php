<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('skins', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->string('rarity', 20)->default('Common'); // Common|Rare|Epic|Legendary
            $table->decimal('price_usd', 8, 2)->default(0);
            $table->integer('gem_cost')->default(0);
            $table->string('shade', 10)->default('#d0d0d0');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('user_skins', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('skin_id');
            $table->string('source', 20)->default('purchase'); // purchase | wheel | quest
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('skin_id')->references('id')->on('skins')->cascadeOnDelete();
            $table->unique(['user_id', 'skin_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_skins');
        Schema::dropIfExists('skins');
    }
};
