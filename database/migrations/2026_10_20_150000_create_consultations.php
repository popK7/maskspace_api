<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('medecin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('specialty', 40);
            $table->string('mode', 20)->default('cabinet');
            $table->string('note', 500)->nullable();
            $table->string('status', 20)->index();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['specialty', 'status']);
            $table->index(['client_id', 'status']);
            $table->index(['medecin_id', 'status']);
        });

        Schema::create('consultation_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultation_id')->constrained('consultations')->cascadeOnDelete();
            $table->foreignId('medecin_id')->constrained('users')->cascadeOnDelete();
            $table->string('message', 500);
            $table->string('status', 20)->index();
            $table->timestamps();

            $table->unique(['consultation_id', 'medecin_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultation_offers');
        Schema::dropIfExists('consultations');
    }
};
