<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Notation client -> prestataire : consultations, soins/beaute, logement. */
return new class extends Migration
{
    /** @var array<string, string> table => colonne du prestataire */
    private array $tables = [
        'consultations' => 'medecin_id',
        'beauty_requests' => 'pro_id',
        'housing_requests' => 'demarcheur_id',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table => $provider) {
            Schema::table($table, function (Blueprint $t) use ($provider) {
                $t->unsignedTinyInteger('rating')->nullable();
                $t->string('review', 500)->nullable();
                $t->index([$provider, 'rating']);
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table => $provider) {
            Schema::table($table, function (Blueprint $t) use ($provider) {
                $t->dropIndex([$provider, 'rating']);
                $t->dropColumn(['rating', 'review']);
            });
        }
    }
};
