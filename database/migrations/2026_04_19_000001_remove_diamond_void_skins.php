<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('skins')->whereIn('name', ['Diamond', 'Void'])->delete();
        DB::table('users')->whereIn('active_skin', ['Diamond', 'Void'])
            ->update(['active_skin' => 'Obsidian']);
    }
    public function down(): void {}
};
