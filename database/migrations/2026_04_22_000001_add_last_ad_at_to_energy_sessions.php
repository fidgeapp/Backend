<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('energy_sessions', function (Blueprint $table) {
            $table->timestamp('last_ad_at')->nullable()->after('ads_watched');
        });
    }
    public function down(): void {
        Schema::table('energy_sessions', function (Blueprint $table) {
            $table->dropColumn('last_ad_at');
        });
    }
};
