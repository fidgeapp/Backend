<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Only Obsidian is free default
        DB::table('skins')->where('name', 'Obsidian')->update(['is_default' => true, 'gem_cost' => 0]);

        // Chrome and all others are NOT default
        DB::table('skins')->where('name', '!=', 'Obsidian')->update(['is_default' => false]);

        // Set Chrome cost to 30 gems
        DB::table('skins')->where('name', 'Chrome')->update(['gem_cost' => 30]);
    }
    public function down(): void {}
};
