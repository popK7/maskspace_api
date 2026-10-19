<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Service boutique. Tous les montants sont des ENTIERS en centimes (jamais de float).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->json('categories');                      // BoutiqueCategory[]
            $table->string('logo_path')->nullable();
            $table->string('cover_path')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('address')->nullable();
            $table->string('neighborhood')->nullable();
            $table->string('city', 100)->default('Casablanca');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('is_active')->default(true)->index();   // visible au catalogue
            $table->boolean('is_open')->default(true);              // interrupteur manuel ouvert/ferme
            $table->json('opening_hours')->nullable();              // {"mon":[{"open":"09:00","close":"19:00"}], ...}
            $table->boolean('supports_delivery')->default(true);
            $table->boolean('supports_pickup')->default(true);
            $table->unsignedInteger('delivery_fee_cents')->default(1800);
            $table->unsignedInteger('free_delivery_threshold_cents')->nullable();
            $table->unsignedInteger('min_order_cents')->default(0);
            $table->unsignedSmallInteger('delivery_radius_km')->nullable();
            $table->unsignedSmallInteger('prep_minutes')->default(20);
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->unsignedInteger('orders_count')->default(0);    // commandes livrees
            $table->timestamps();
            $table->softDeletes();

            $table->index(['latitude', 'longitude']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('origin')->nullable();
            $table->string('unit')->nullable();                      // conditionnement : "sachet 500 g"
            $table->string('category', 30);                          // BoutiqueCategory
            $table->unsignedInteger('price_cents');
            $table->unsignedInteger('compare_at_price_cents')->nullable();   // prix barre (promo)
            $table->boolean('track_stock')->default(true);
            $table->unsignedInteger('stock_quantity')->default(0);
            $table->unsignedInteger('low_stock_threshold')->default(5);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();                                   // les commandes gardent un snapshot

            $table->index(['shop_id', 'is_active']);
            $table->index(['shop_id', 'category']);
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('path');                                   // chemin disque public OU URL absolue (donnees demo)
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('type', 10);                               // percent | fixed
            $table->unsignedInteger('value');                         // % (1-100) ou centimes
            $table->unsignedInteger('min_order_cents')->default(0);
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('uses_count')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['shop_id', 'code']);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 24)->unique();
            $table->foreignId('shop_id')->constrained();
            $table->foreignId('customer_id')->constrained('users');
            $table->foreignId('livreur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->index();
            $table->string('fulfillment_type', 10);                   // delivery | pickup
            $table->string('payment_method', 10)->default('cash');
            $table->string('payment_status', 10)->default('pending'); // pending | paid
            $table->unsignedInteger('subtotal_cents');
            $table->unsignedInteger('discount_cents')->default(0);
            $table->unsignedInteger('delivery_fee_cents')->default(0);
            $table->unsignedInteger('total_cents');
            $table->decimal('commission_rate', 5, 2)->default(0);     // % plateforme fige a la commande
            $table->unsignedInteger('commission_cents')->default(0);
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->string('coupon_code', 32)->nullable();
            $table->string('delivery_address')->nullable();
            $table->decimal('delivery_latitude', 10, 7)->nullable();
            $table->decimal('delivery_longitude', 10, 7)->nullable();
            $table->string('customer_phone', 32);
            $table->text('customer_note')->nullable();
            $table->string('delivery_pin', 4)->nullable();            // code de remise donne par le client au livreur
            $table->string('rejection_reason')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->unsignedSmallInteger('prep_minutes')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['customer_id', 'idempotency_key']);
            $table->index(['shop_id', 'status']);
            $table->index(['customer_id', 'created_at']);
            $table->index(['livreur_id', 'status']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');                                   // snapshot
            $table->string('unit')->nullable();                       // snapshot
            $table->unsignedInteger('unit_price_cents');              // snapshot
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('total_cents');
            $table->timestamps();
        });

        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->text('reply')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['reviews', 'order_status_histories', 'order_items', 'orders', 'coupons', 'product_images', 'products', 'shops'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
