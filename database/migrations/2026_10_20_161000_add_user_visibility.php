<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_visible')->default(true)->after('status');
        });

        // Les comptes encore bloqués sur l'ancien code e-mail restent connectables, mais invisibles.
        DB::table('users')->where('status', 'pending')->update([
            'status' => 'active',
            'is_visible' => false,
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_visible');
        });
    }
};
