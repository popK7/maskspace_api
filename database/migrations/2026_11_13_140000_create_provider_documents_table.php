<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->string('type', 40);
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime', 100)->nullable();
            $table->unsignedInteger('size')->default(0);
            $table->string('status', 20)->default('pending');
            $table->string('rejection_reason', 500)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'role', 'type']);
            $table->index(['status', 'created_at']);
        });

        Schema::table('user_roles', function (Blueprint $table) {
            $table->timestamp('verified_at')->nullable()->after('profile_completed_at');
        });

        // Les prestataires deja actifs ne sont pas bloques par l'arrivee de la verification.
        DB::table('user_roles')
            ->whereNotNull('profile_completed_at')
            ->update(['verified_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('user_roles', function (Blueprint $table) {
            $table->dropColumn('verified_at');
        });
        Schema::dropIfExists('provider_documents');
    }
};
