<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone', 32)->index();
            $table->string('role', 20)->index();
            $table->string('status', 20)->default('active')->index();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });

        // Donnees specifiques au role (1-1) : commerce, vehicule, specialites.
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('shop_name')->nullable();
            $table->string('vehicle_model')->nullable();
            $table->string('vehicle_plate', 32)->nullable();
            $table->json('specialties')->nullable();
            $table->timestamps();
        });

        // Refresh tokens opaques : seul le hash SHA-256 est stocke.
        // `family_id` identifie une session (un appareil) pour detecter la reutilisation d'un token deja tourne.
        Schema::create('refresh_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('family_id')->index();
            $table->char('token_hash', 64)->unique();
            $table->string('device_name', 100)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        // Codes a usage unique (reset mot de passe, verification e-mail).
        Schema::create('one_time_codes', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
            $table->string('purpose', 30);
            $table->char('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['email', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('one_time_codes');
        Schema::dropIfExists('refresh_tokens');
        Schema::dropIfExists('user_profiles');
        Schema::dropIfExists('users');
    }
};
