<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('housing_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('demarcheur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 20);
            $table->string('zone', 40);
            $table->string('billing', 10)->default('night');
            $table->unsignedInteger('budget_cents')->nullable();
            $table->unsignedTinyInteger('guests')->default(1);
            $table->string('note', 500)->nullable();
            $table->string('status', 20)->index();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['zone', 'status']);
            $table->index(['client_id', 'status']);
            $table->index(['demarcheur_id', 'status']);
        });

        Schema::create('housing_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('housing_request_id')->constrained('housing_requests')->cascadeOnDelete();
            $table->foreignId('demarcheur_id')->constrained('users')->cascadeOnDelete();
            $table->string('message', 500);
            $table->string('status', 20)->index();
            $table->timestamps();

            $table->unique(['housing_request_id', 'demarcheur_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('housing_offers');
        Schema::dropIfExists('housing_requests');
    }
};
