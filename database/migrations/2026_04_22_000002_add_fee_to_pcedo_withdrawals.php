<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('pcedo_withdrawals', function (Blueprint $table) {
            $table->decimal('fee', 12, 4)->default(0)->after('amount');
        });
    }
    public function down(): void {
        Schema::table('pcedo_withdrawals', function (Blueprint $table) {
            $table->dropColumn('fee');
        });
    }
};
