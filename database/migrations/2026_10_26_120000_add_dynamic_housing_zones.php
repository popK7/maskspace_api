<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->json('housing_zones')->nullable();
        });

        Schema::table('housing_requests', function (Blueprint $table) {
            $table->string('zone_name', 150)->nullable();
            $table->string('zone_city', 150)->nullable();
            $table->decimal('zone_latitude', 10, 7)->nullable();
            $table->decimal('zone_longitude', 10, 7)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('housing_requests', function (Blueprint $table) {
            $table->dropColumn(['zone_name', 'zone_city', 'zone_latitude', 'zone_longitude']);
        });

        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropColumn('housing_zones');
        });
    }
};
