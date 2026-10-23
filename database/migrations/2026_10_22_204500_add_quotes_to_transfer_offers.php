<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfer_offers', function (Blueprint $table) {
            $table->decimal('exchange_rate', 20, 8)->nullable()->after('eta_minutes');
            $table->unsignedInteger('fee_amount')->nullable()->after('exchange_rate');
        });
    }

    public function down(): void
    {
        Schema::table('transfer_offers', function (Blueprint $table) {
            $table->dropColumn(['exchange_rate', 'fee_amount']);
        });
    }
};
