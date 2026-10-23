<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artisan_jobs', function (Blueprint $table) {
            $table->unsignedInteger('final_price_cents')->nullable()->after('eta_minutes');
            $table->string('final_price_status', 20)->default('not_submitted')->after('final_price_cents');
        });
    }

    public function down(): void
    {
        Schema::table('artisan_jobs', function (Blueprint $table) {
            $table->dropColumn(['final_price_cents', 'final_price_status']);
        });
    }
};
