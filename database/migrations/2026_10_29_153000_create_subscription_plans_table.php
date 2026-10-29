<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 80);
            $table->unsignedSmallInteger('order_limit')->nullable();
            $table->string('price_label', 100)->default('Tarif à confirmer au point de vente');
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();
        DB::table('subscription_plans')->insert([
            [
                'code' => 'orders_10',
                'name' => 'Essentiel',
                'order_limit' => 10,
                'price_label' => 'Tarif à confirmer au point de vente',
                'description' => 'Pour démarrer votre activité.',
                'is_active' => true,
                'sort_order' => 10,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'orders_15',
                'name' => 'Plus',
                'order_limit' => 15,
                'price_label' => 'Tarif à confirmer au point de vente',
                'description' => 'Un volume supplémentaire pour votre activité.',
                'is_active' => true,
                'sort_order' => 20,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'orders_30',
                'name' => 'Pro',
                'order_limit' => 30,
                'price_label' => 'Tarif à confirmer au point de vente',
                'description' => 'Pour les activités à fort volume.',
                'is_active' => true,
                'sort_order' => 30,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'contact',
                'name' => 'Sur mesure',
                'order_limit' => null,
                'price_label' => 'Tarif à confirmer au point de vente',
                'description' => 'Une formule adaptée, après accord au point de vente.',
                'is_active' => true,
                'sort_order' => 40,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        Schema::table('subscription_receipts', function (Blueprint $table) {
            $table->string('plan_name', 80)->nullable()->after('plan');
        });

        DB::table('subscription_receipts')
            ->whereNull('plan_name')
            ->update([
                'plan_name' => DB::raw('(select name from subscription_plans where subscription_plans.code = subscription_receipts.plan)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('subscription_receipts', function (Blueprint $table) {
            $table->dropColumn('plan_name');
        });

        Schema::dropIfExists('subscription_plans');
    }
};
