<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lodgings', function (Blueprint $table) {
            $table->string('billing', 10)->default('night')->after('price_cents');
        });

        Schema::table('lodging_bookings', function (Blueprint $table) {
            $table->string('billing', 10)->default('night')->after('nights');
        });
    }

    public function down(): void
    {
        Schema::table('lodging_bookings', function (Blueprint $table) {
            $table->dropColumn('billing');
        });
        Schema::table('lodgings', function (Blueprint $table) {
            $table->dropColumn('billing');
        });
    }
};
