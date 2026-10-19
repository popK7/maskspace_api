<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artisan_drivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artisan_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['artisan_id', 'driver_id']);
        });

        Schema::table('rides', function (Blueprint $table) {
            $table->foreignId('offered_driver_id')->nullable()->after('driver_id')->constrained('users')->nullOnDelete();
            $table->timestamp('offer_expires_at')->nullable()->after('offered_driver_id');
            $table->index(['offered_driver_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropConstrainedForeignId('offered_driver_id');
            $table->dropColumn('offer_expires_at');
        });
        Schema::dropIfExists('artisan_drivers');
    }
};
