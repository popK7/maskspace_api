<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Livreurs "de confiance" d'une boutique : ceux avec qui elle a l'habitude de travailler.
        Schema::create('shop_couriers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('livreur_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['shop_id', 'livreur_id']);
        });

        // Course proposee en priorite a un livreur precis (offre limitee dans le temps).
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('offered_livreur_id')->nullable()->after('livreur_id')->constrained('users')->nullOnDelete();
            $table->timestamp('offer_expires_at')->nullable()->after('offered_livreur_id');
            $table->index(['offered_livreur_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('offered_livreur_id');
            $table->dropColumn('offer_expires_at');
        });
        Schema::dropIfExists('shop_couriers');
    }
};
