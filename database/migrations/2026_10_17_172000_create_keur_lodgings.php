<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lodgings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('host_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 20)->index();
            $table->string('title');
            $table->string('description', 2000)->nullable();
            $table->string('address');
            $table->string('neighborhood')->nullable();
            $table->string('city')->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->unsignedTinyInteger('guests')->default(2);
            $table->unsignedTinyInteger('bedrooms')->default(1);
            $table->unsignedTinyInteger('beds')->default(1);
            $table->unsignedTinyInteger('bathrooms')->default(1);
            $table->unsignedInteger('price_cents');
            $table->json('amenities')->nullable();
            $table->boolean('is_active')->default(false);
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'kind']);
            $table->index(['host_id', 'is_active']);
        });

        Schema::create('lodging_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lodging_id')->constrained('lodgings')->cascadeOnDelete();
            $table->string('path');
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('caption', 120)->nullable();
            $table->timestamps();
        });

        Schema::create('lodging_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('host_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('lodging_id')->constrained('lodgings')->cascadeOnDelete();
            $table->date('check_in');
            $table->date('check_out');
            $table->unsignedTinyInteger('guests')->default(1);
            $table->unsignedSmallInteger('nights');
            $table->unsignedInteger('total_cents');
            $table->string('status', 20)->index();
            $table->string('note', 500)->nullable();
            $table->unsignedTinyInteger('rating')->nullable();
            $table->string('review', 500)->nullable();
            $table->string('cancelled_by', 20)->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['lodging_id', 'status']);
            $table->index(['client_id', 'status']);
            $table->index(['host_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lodging_bookings');
        Schema::dropIfExists('lodging_photos');
        Schema::dropIfExists('lodgings');
    }
};
