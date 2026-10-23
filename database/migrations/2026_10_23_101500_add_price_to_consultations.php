<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            $table->unsignedInteger('price_dh')->nullable()->after('note');
            $table->string('price_status', 20)->default('not_submitted')->after('price_dh');
            $table->timestamp('price_proposed_at')->nullable()->after('price_status');
            $table->timestamp('price_accepted_at')->nullable()->after('price_proposed_at');
        });
    }

    public function down(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            $table->dropColumn([
                'price_dh',
                'price_status',
                'price_proposed_at',
                'price_accepted_at',
            ]);
        });
    }
};
