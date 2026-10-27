<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->string('role', 20)->nullable()->after('user_id');
            $table->dropUnique('user_profiles_user_id_unique');
        });

        DB::table('users')
            ->select(['id', 'role', 'is_visible', 'profile_completed_at'])
            ->orderBy('id')
            ->each(function (object $user): void {
                DB::table('user_profiles')
                    ->where('user_id', $user->id)
                    ->update(['role' => $user->role]);
            });

        Schema::table('user_profiles', function (Blueprint $table) {
            $table->unique(['user_id', 'role']);
        });

        Schema::create('user_roles', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20);
            $table->boolean('is_visible')->default(false);
            $table->timestamp('profile_completed_at')->nullable();
            $table->timestamps();
            $table->primary(['user_id', 'role']);
            $table->index(['role', 'is_visible']);
        });

        DB::table('users')
            ->select(['id', 'role', 'is_visible', 'profile_completed_at', 'created_at', 'updated_at'])
            ->orderBy('id')
            ->each(function (object $user): void {
                DB::table('user_roles')->insert([
                    'user_id' => $user->id,
                    'role' => $user->role,
                    'is_visible' => $user->is_visible ?? false,
                    'profile_completed_at' => $user->profile_completed_at,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_roles');

        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropUnique('user_profiles_user_id_role_unique');
        });

        DB::table('user_profiles')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('users')
                    ->whereColumn('users.id', 'user_profiles.user_id')
                    ->whereColumn('users.role', 'user_profiles.role');
            })
            ->delete();

        Schema::table('user_profiles', function (Blueprint $table) {
            $table->dropColumn('role');
            $table->unique('user_id');
        });
    }
};
