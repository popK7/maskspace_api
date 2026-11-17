<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Connexion par telephone : `phone_number` est le numero VERIFIE (format E.164, via Firebase),
 * unique et utilise comme identifiant. `phone` reste le numero de contact affiche.
 * L'e-mail devient facultatif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('phone_number', 20)->nullable()->unique()->after('phone');
            $table->timestamp('phone_verified_at')->nullable()->after('email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone_number']);
            $table->dropColumn(['phone_number', 'phone_verified_at']);
        });
    }
};
