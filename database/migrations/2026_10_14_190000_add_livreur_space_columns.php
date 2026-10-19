<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Disponibilite : un livreur "hors service" ne voit plus de courses et ne peut pas en prendre.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_available')->default(true)->after('status');
        });

        // Note du livreur donnee par le client en meme temps que l'avis boutique.
        Schema::table('reviews', function (Blueprint $table) {
            $table->foreignId('livreur_id')->nullable()->after('customer_id')->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('courier_rating')->nullable()->after('rating');
            $table->index(['livreur_id', 'courier_rating']);
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropConstrainedForeignId('livreur_id');
            $table->dropColumn('courier_rating');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_available');
        });
    }
};
