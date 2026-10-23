<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ride_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ride_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedInteger('price_cents');
            $table->unsignedSmallInteger('eta_minutes');
            $table->decimal('distance_to_pickup_km', 6, 2);
            $table->timestamps();
            $table->unique(['ride_id', 'driver_id']);
            $table->index(['ride_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ride_offers');
    }
};
