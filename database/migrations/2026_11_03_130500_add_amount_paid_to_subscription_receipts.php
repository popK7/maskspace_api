<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_receipts', function (Blueprint $table) {
            $table->decimal('amount_paid_usd', 10, 2)->nullable()->after('reference_price_usd');
            $table->index('activated_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_receipts', function (Blueprint $table) {
            $table->dropIndex(['activated_at']);
            $table->dropColumn('amount_paid_usd');
        });
    }
};
