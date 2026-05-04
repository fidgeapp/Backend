<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('gem_purchase_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('gem_amount');       // gems the user wants to receive
            $table->decimal('eth_amount', 18, 8); // ETH they need to send
            $table->string('wallet_address');     // our ETH address they send to
            $table->string('tx_hash')->nullable(); // user-submitted tx hash
            $table->enum('status', ['pending', 'submitted', 'verified', 'rejected'])->default('pending');
            $table->string('coupon_code')->nullable(); // coupon issued after verify
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gem_purchase_requests');
    }
};
