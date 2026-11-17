<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->unsignedTinyInteger('rating')->nullable()->after('cancel_reason');
            $table->string('review', 500)->nullable()->after('rating');
            $table->index(['driver_id', 'rating']);
        });
    }

    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropIndex(['driver_id', 'rating']);
            $table->dropColumn(['rating', 'review']);
        });
    }
};
