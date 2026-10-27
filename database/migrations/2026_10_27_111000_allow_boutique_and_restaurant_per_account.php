<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasIndex('shops', ['owner_id', 'kind'], 'unique')) {
            Schema::table('shops', function (Blueprint $table) {
                $table->unique(['owner_id', 'kind']);
            });
        }

        $ownerUniqueIndex = collect(Schema::getIndexes('shops'))
            ->first(fn (array $index): bool => $index['unique'] && $index['columns'] === ['owner_id']);

        if ($ownerUniqueIndex !== null) {
            Schema::table('shops', function (Blueprint $table) use ($ownerUniqueIndex) {
                $table->dropUnique($ownerUniqueIndex['name']);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasIndex('shops', ['owner_id'], 'unique')) {
            Schema::table('shops', function (Blueprint $table) {
                $table->unique('owner_id');
            });
        }

        $ownerKindUniqueIndex = collect(Schema::getIndexes('shops'))
            ->first(fn (array $index): bool => $index['unique'] && $index['columns'] === ['owner_id', 'kind']);

        if ($ownerKindUniqueIndex !== null) {
            Schema::table('shops', function (Blueprint $table) use ($ownerKindUniqueIndex) {
                $table->dropUnique($ownerKindUniqueIndex['name']);
            });
        }
    }
};
