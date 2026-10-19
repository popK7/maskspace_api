<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            // Ouverture exceptionnelle : outrepasse les horaires jusqu'a cette date (le boutiquier ouvre hors creneau).
            $table->timestamp('force_open_until')->nullable()->after('opening_hours');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('force_open_until');
        });
    }
};
