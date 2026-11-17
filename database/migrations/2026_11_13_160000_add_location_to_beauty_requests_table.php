<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Position du client sur une demande beaute : elle n'est proposee (et notifiee)
 * qu'aux pros les plus proches. Nullable : les demandes deja creees et celles des
 * anciennes versions de l'app restent visibles par specialite, comme avant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beauty_requests', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('mode');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->index(['status', 'latitude', 'longitude']);
        });
    }

    public function down(): void
    {
        Schema::table('beauty_requests', function (Blueprint $table) {
            $table->dropIndex(['status', 'latitude', 'longitude']);
            $table->dropColumn(['latitude', 'longitude']);
        });
    }
};
