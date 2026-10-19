<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restaurants : meme moteur que les boutiques, avec un type de commerce, des plats enrichis
 * (plat du jour, piment, vegetarien, temps de preparation, epuise aujourd'hui) et un « mode rush ».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->string('kind', 20)->default('boutique')->after('owner_id')->index();
            $table->unsignedSmallInteger('rush_extra_minutes')->default(0);   // delai ajoute quand la cuisine est debordee
            $table->timestamp('rush_until')->nullable();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_featured')->default(false);                    // plat du jour / coup de coeur
            $table->unsignedTinyInteger('spice_level')->default(0);            // 0 doux ... 3 tres fort
            $table->boolean('is_vegetarian')->default(false);
            $table->unsignedSmallInteger('prep_minutes')->nullable();          // temps propre au plat
            $table->timestamp('sold_out_until')->nullable();                   // « epuise aujourd'hui » : se leve tout seul
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['is_featured', 'spice_level', 'is_vegetarian', 'prep_minutes', 'sold_out_until']);
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['kind', 'rush_extra_minutes', 'rush_until']);
        });
    }
};
