<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('money_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('changeur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('amount');
            $table->string('currency', 3);
            $table->string('destination_country', 40)->index();
            $table->string('status', 20)->index();
            $table->string('note', 500)->nullable();
            $table->string('address');
            $table->string('neighborhood')->nullable();
            $table->string('city')->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedSmallInteger('eta_minutes')->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->string('review', 500)->nullable();
            $table->string('cancelled_by', 20)->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('meeting_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'destination_country']);
            $table->index(['client_id', 'status']);
            $table->index(['changeur_id', 'status']);
        });

        Schema::create('transfer_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')->constrained('money_transfers')->cascadeOnDelete();
            $table->foreignId('changeur_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedSmallInteger('eta_minutes');
            $table->decimal('distance_km', 6, 2)->nullable();
            $table->timestamps();

            $table->unique(['transfer_id', 'changeur_id']);
        });

        Schema::table('rides', function (Blueprint $table) {
            $table->foreignId('transfer_id')->nullable()->after('job_id')->constrained('money_transfers')->nullOnDelete();
        });

        Schema::table('money_transfers', function (Blueprint $table) {
            $table->foreignId('ride_id')->nullable()->after('changeur_id')->constrained('rides')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('money_transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ride_id');
        });
        Schema::table('rides', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transfer_id');
        });
        Schema::dropIfExists('transfer_offers');
        Schema::dropIfExists('money_transfers');
    }
};
