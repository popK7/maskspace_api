<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beauty_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('pro_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('specialty', 40);
            $table->string('mode', 20)->default('salon');
            $table->string('note', 500)->nullable();
            $table->string('status', 20)->index();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['specialty', 'status']);
            $table->index(['client_id', 'status']);
            $table->index(['pro_id', 'status']);
        });

        Schema::create('beauty_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('beauty_request_id')->constrained('beauty_requests')->cascadeOnDelete();
            $table->foreignId('pro_id')->constrained('users')->cascadeOnDelete();
            $table->string('message', 500);
            $table->string('status', 20)->index();
            $table->timestamps();

            $table->unique(['beauty_request_id', 'pro_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beauty_offers');
        Schema::dropIfExists('beauty_requests');
    }
};
