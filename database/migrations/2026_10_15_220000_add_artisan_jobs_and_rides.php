<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamp('location_updated_at')->nullable();
            $table->index(['latitude', 'longitude']);
        });

        Schema::create('artisan_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('artisan_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('trade', 40)->index();
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
            $table->timestamp('en_route_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'trade']);
            $table->index(['client_id', 'status']);
            $table->index(['artisan_id', 'status']);
        });

        Schema::create('job_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('artisan_jobs')->cascadeOnDelete();
            $table->foreignId('artisan_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedSmallInteger('eta_minutes');
            $table->decimal('distance_km', 6, 2)->nullable();
            $table->timestamps();

            $table->unique(['job_id', 'artisan_id']);
        });

        Schema::create('rides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('passenger_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('job_id')->nullable()->constrained('artisan_jobs')->nullOnDelete();
            $table->string('status', 20)->index();
            $table->string('pickup_address');
            $table->decimal('pickup_latitude', 10, 7);
            $table->decimal('pickup_longitude', 10, 7);
            $table->string('dropoff_address');
            $table->decimal('dropoff_latitude', 10, 7);
            $table->decimal('dropoff_longitude', 10, 7);
            $table->decimal('distance_km', 6, 2);
            $table->unsignedInteger('price_cents');
            $table->unsignedSmallInteger('eta_minutes')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'driver_id']);
            $table->index(['passenger_id', 'status']);
        });

        Schema::table('artisan_jobs', function (Blueprint $table) {
            $table->foreignId('ride_id')->nullable()->after('artisan_id')->constrained('rides')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('artisan_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ride_id');
        });
        Schema::dropIfExists('rides');
        Schema::dropIfExists('job_offers');
        Schema::dropIfExists('artisan_jobs');
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['latitude', 'longitude']);
            $table->dropColumn(['latitude', 'longitude', 'location_updated_at']);
        });
    }
};
