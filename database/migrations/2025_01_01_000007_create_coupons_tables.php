<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('type', 20);          // gems | points
            $table->decimal('value', 12, 4);
            $table->integer('max_uses')->default(1);   // 0 = unlimited
            $table->integer('used_count')->default(0);
            $table->date('expiry_date')->nullable();
            $table->boolean('active')->default(true);
            $table->string('created_by', 50)->default('admin');
            $table->timestamps();

            $table->index('code');
            $table->index('active');
        });

        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('coupon_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();

            $table->foreign('coupon_id')->references('id')->on('coupons')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['coupon_id', 'user_id']); // one redemption per user per coupon
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('coupons');
    }
};
