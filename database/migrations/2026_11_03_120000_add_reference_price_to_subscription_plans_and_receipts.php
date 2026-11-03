<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->decimal('reference_price_usd', 10, 2)->nullable()->after('order_limit');
        });

        Schema::table('subscription_receipts', function (Blueprint $table) {
            $table->decimal('reference_price_usd', 10, 2)->nullable()->after('order_limit');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_receipts', function (Blueprint $table) {
            $table->dropColumn('reference_price_usd');
        });

        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn('reference_price_usd');
        });
    }
};
