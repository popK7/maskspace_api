<?php

use App\Http\Controllers\Api\V1\Admin\AppReleaseController;
use App\Http\Controllers\Api\V1\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\V1\Artisan\DriverController as ArtisanDriverController;
use App\Http\Controllers\Api\V1\Artisan\JobController as ArtisanJobController;
use App\Http\Controllers\Api\V1\Artisan\PresenceController as ArtisanPresenceController;
use App\Http\Controllers\Api\V1\Artisan\PresenceController as ChangeurPresenceController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Beaute\BeautyController as BeauteBeautyController;
use App\Http\Controllers\Api\V1\Beauty\BeautyController;
use App\Http\Controllers\Api\V1\Care\ConsultationController;
use App\Http\Controllers\Api\V1\Changeur\TransferController as ChangeurTransferController;
use App\Http\Controllers\Api\V1\ChatController;
use App\Http\Controllers\Api\V1\Demarcheur\HousingController as DemarcheurHousingController;
use App\Http\Controllers\Api\V1\DownloadController;
use App\Http\Controllers\Api\V1\Driver\PresenceController as DriverPresenceController;
use App\Http\Controllers\Api\V1\Driver\RideController as DriverRideController;
use App\Http\Controllers\Api\V1\Hote\LodgingController as HoteLodgingController;
use App\Http\Controllers\Api\V1\Housing\HousingRequestController;
use App\Http\Controllers\Api\V1\Job\JobController;
use App\Http\Controllers\Api\V1\Keur\BookingController;
use App\Http\Controllers\Api\V1\Keur\CatalogController;
use App\Http\Controllers\Api\V1\Livreur\DeliveryController;
use App\Http\Controllers\Api\V1\Livreur\LocationController;
use App\Http\Controllers\Api\V1\Medecin\ConsultationController as MedecinConsultationController;
use App\Http\Controllers\Api\V1\Merchant\CouponController as MerchantCouponController;
use App\Http\Controllers\Api\V1\Merchant\CourierController as MerchantCourierController;
use App\Http\Controllers\Api\V1\Merchant\OrderController as MerchantOrderController;
use App\Http\Controllers\Api\V1\Merchant\ProductController as MerchantProductController;
use App\Http\Controllers\Api\V1\Merchant\ReviewController as MerchantReviewController;
use App\Http\Controllers\Api\V1\Merchant\ShopController as MerchantShopController;
use App\Http\Controllers\Api\V1\Merchant\StatsController as MerchantStatsController;
use App\Http\Controllers\Api\V1\PlaceSearchController;
use App\Http\Controllers\Api\V1\PushDeviceController;
use App\Http\Controllers\Api\V1\Ride\RideController;
use App\Http\Controllers\Api\V1\Shop\FavoriteController;
use App\Http\Controllers\Api\V1\Shop\OrderController;
use App\Http\Controllers\Api\V1\Shop\ShopController;
use App\Http\Controllers\Api\V1\Shop\TrackingController;
use App\Http\Controllers\Api\V1\Transfer\TransferController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1  —  prefixe /api/v1  (stateless, JWT)
|--------------------------------------------------------------------------
*/
Route::prefix('v1')->middleware('throttle:api')->group(function () {

    // ---- Authentification (public) ----
    Route::prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register'])->middleware('throttle:register');
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
        Route::post('google', [AuthController::class, 'google'])->middleware('throttle:login');
        Route::post('refresh', [AuthController::class, 'refresh'])->middleware('throttle:refresh');
        Route::post('forgot-password', [PasswordResetController::class, 'forgot'])->middleware('throttle:forgot-password');
        Route::post('reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:reset-password');

        // ---- Authentification (access token requis) ----
        // Le logout reste possible pour un compte suspendu (pas de middleware `active`).
        Route::middleware('auth:api')->group(function () {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::post('logout-all', [AuthController::class, 'logoutAll']);
        });

        Route::middleware(['auth:api', 'not-suspended'])->group(function () {
            Route::post('activation', [EmailVerificationController::class, 'requestActivation'])->middleware('throttle:30,1');
            Route::post('email/verify', [EmailVerificationController::class, 'verify'])->middleware('throttle:verify-email');
            Route::post('email/resend', [EmailVerificationController::class, 'resend'])->middleware('throttle:otp-resend');
        });
    });

    // ---- Catalogue public (consultation sans compte) ----
    Route::get('places/search', [PlaceSearchController::class, 'search'])->middleware('throttle:geocoding');
    Route::get('shops', [ShopController::class, 'index']);
    Route::get('shops/{shop}', [ShopController::class, 'show'])->whereNumber('shop');
    Route::get('shops/{shop}/products', [ShopController::class, 'products'])->whereNumber('shop');
    Route::get('shops/{shop}/reviews', [ShopController::class, 'reviews'])->whereNumber('shop');

    Route::get('downloads', [DownloadController::class, 'index']);
    Route::get('downloads/{platform}', [DownloadController::class, 'show'])->whereIn('platform', ['android', 'ios']);

    Route::get('lodgings', [CatalogController::class, 'index']);
    Route::get('lodgings/{lodging}', [CatalogController::class, 'show'])->whereNumber('lodging');

    // Profil et consultation du compte, y compris tant que l'activation n'est pas faite.
    Route::middleware(['auth:api', 'not-suspended'])->group(function () {
        Route::get('me', [MeController::class, 'show']);
        Route::post('me/profile', [MeController::class, 'complete'])->middleware('throttle:20,1');
        Route::post('me/push-devices', [PushDeviceController::class, 'store'])->middleware('throttle:30,1');
        Route::delete('me/push-devices', [PushDeviceController::class, 'destroy'])->middleware('throttle:30,1');
    });

    // ---- Routes authentifiees ----
    Route::middleware(['auth:api', 'active'])->group(function () {
        Route::patch('me', [MeController::class, 'update']);
        Route::put('me/password', [MeController::class, 'updatePassword']);

        // Chat rattache a une demande (commande, intervention, course).
        Route::get('chats', [ChatController::class, 'index']);
        Route::get('chats/{conversation}', [ChatController::class, 'show'])->whereNumber('conversation');
        Route::post('chats/{conversation}/messages', [ChatController::class, 'storeMessage'])
            ->whereNumber('conversation')->middleware('throttle:60,1');

        // ---- Client : commander ----
        Route::middleware('role:client')->group(function () {
            Route::post('rides', [RideController::class, 'store'])->middleware('throttle:20,1');
            Route::get('rides/{ride}/offers', [RideController::class, 'offers'])->whereNumber('ride');
            Route::post('rides/{ride}/offers/{offer}/choose', [RideController::class, 'chooseOffer'])
                ->whereNumber(['ride', 'offer']);

            Route::post('orders/quote', [OrderController::class, 'quote'])->middleware('throttle:60,1');
            Route::post('orders', [OrderController::class, 'store'])->middleware('throttle:20,1');
            Route::get('orders', [OrderController::class, 'index']);
            Route::get('orders/{order}', [OrderController::class, 'show'])->whereNumber('order');
            Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->whereNumber('order');
            Route::get('orders/{order}/tracking', TrackingController::class)->whereNumber('order')->middleware('throttle:60,1');
            Route::post('orders/{order}/review', [OrderController::class, 'review'])->whereNumber('order');

            Route::get('favorites', [FavoriteController::class, 'index']);
            Route::get('favorites/ids', [FavoriteController::class, 'ids']);
            Route::put('favorites/{kind}/{id}', [FavoriteController::class, 'add'])
                ->whereIn('kind', ['shop', 'product'])->whereNumber('id')->middleware('throttle:120,1');
            Route::delete('favorites/{kind}/{id}', [FavoriteController::class, 'remove'])
                ->whereIn('kind', ['shop', 'product'])->whereNumber('id')->middleware('throttle:120,1');

            Route::post('jobs', [JobController::class, 'store'])->middleware('throttle:20,1');
            Route::get('jobs', [JobController::class, 'index']);
            Route::get('jobs/{job}', [JobController::class, 'show'])->whereNumber('job');
            Route::post('jobs/{job}/choose', [JobController::class, 'choose'])->whereNumber('job');
            Route::post('jobs/{job}/accept-final-price', [JobController::class, 'acceptFinalPrice'])->whereNumber('job');
            Route::post('jobs/{job}/cancel', [JobController::class, 'cancel'])->whereNumber('job');
            Route::post('jobs/{job}/review', [JobController::class, 'review'])->whereNumber('job');
            Route::get('jobs/{job}/tracking', [JobController::class, 'tracking'])->whereNumber('job')->middleware('throttle:60,1');

            Route::post('transfers', [TransferController::class, 'store'])->middleware('throttle:20,1');
            Route::get('transfers', [TransferController::class, 'index']);
            Route::get('transfers/{transfer}', [TransferController::class, 'show'])->whereNumber('transfer');
            Route::post('transfers/{transfer}/choose', [TransferController::class, 'choose'])->whereNumber('transfer');
            Route::post('transfers/{transfer}/meet', [TransferController::class, 'meet'])->whereNumber('transfer');
            Route::post('transfers/{transfer}/complete', [TransferController::class, 'complete'])->whereNumber('transfer');
            Route::post('transfers/{transfer}/cancel', [TransferController::class, 'cancel'])->whereNumber('transfer');
            Route::post('transfers/{transfer}/review', [TransferController::class, 'review'])->whereNumber('transfer');
            Route::get('transfers/{transfer}/tracking', [TransferController::class, 'tracking'])->whereNumber('transfer')->middleware('throttle:60,1');
            Route::post('transfers/{transfer}/ride', [TransferController::class, 'requestRide'])->whereNumber('transfer')->middleware('throttle:10,1');

            Route::post('bookings', [BookingController::class, 'store'])->middleware('throttle:20,1');
            Route::get('bookings', [BookingController::class, 'index']);
            Route::get('bookings/{booking}', [BookingController::class, 'show'])->whereNumber('booking');
            Route::post('bookings/{booking}/cancel', [BookingController::class, 'cancel'])->whereNumber('booking');
            Route::post('bookings/{booking}/review', [BookingController::class, 'review'])->whereNumber('booking');

            Route::post('housing-requests', [HousingRequestController::class, 'store'])->middleware('throttle:20,1');
            Route::get('housing-requests', [HousingRequestController::class, 'index']);
            Route::get('housing-requests/{housingRequest}', [HousingRequestController::class, 'show'])->whereNumber('housingRequest');
            Route::post('housing-requests/{housingRequest}/choose', [HousingRequestController::class, 'choose'])->whereNumber('housingRequest');
            Route::post('housing-requests/{housingRequest}/complete', [HousingRequestController::class, 'complete'])->whereNumber('housingRequest');
            Route::post('housing-requests/{housingRequest}/cancel', [HousingRequestController::class, 'cancel'])->whereNumber('housingRequest');

            Route::post('consultations', [ConsultationController::class, 'store'])->middleware('throttle:20,1');
            Route::get('consultations', [ConsultationController::class, 'index']);
            Route::get('consultations/{consultation}', [ConsultationController::class, 'show'])->whereNumber('consultation');
            Route::post('consultations/{consultation}/choose', [ConsultationController::class, 'choose'])->whereNumber('consultation');
            Route::post('consultations/{consultation}/complete', [ConsultationController::class, 'complete'])->whereNumber('consultation');
            Route::post('consultations/{consultation}/cancel', [ConsultationController::class, 'cancel'])->whereNumber('consultation');

            Route::post('beauty-requests', [BeautyController::class, 'store'])->middleware('throttle:20,1');
            Route::get('beauty-requests', [BeautyController::class, 'index']);
            Route::get('beauty-requests/{beautyRequest}', [BeautyController::class, 'show'])->whereNumber('beautyRequest');
            Route::post('beauty-requests/{beautyRequest}/choose', [BeautyController::class, 'choose'])->whereNumber('beautyRequest');
            Route::post('beauty-requests/{beautyRequest}/complete', [BeautyController::class, 'complete'])->whereNumber('beautyRequest');
            Route::post('beauty-requests/{beautyRequest}/cancel', [BeautyController::class, 'cancel'])->whereNumber('beautyRequest');
        });

        // ---- Artisan : interventions et appel d'un transporteur ----
        Route::prefix('artisan')->middleware('role:artisan')->group(function () {
            Route::patch('availability', [ArtisanPresenceController::class, 'availability']);
            Route::post('location', [ArtisanPresenceController::class, 'location'])->middleware('throttle:30,1');
            Route::get('dashboard', [ArtisanJobController::class, 'dashboard']);
            Route::get('jobs/available', [ArtisanJobController::class, 'available']);
            Route::get('jobs', [ArtisanJobController::class, 'index']);
            Route::get('jobs/{job}', [ArtisanJobController::class, 'show'])->whereNumber('job');
            Route::post('jobs/{job}/offer', [ArtisanJobController::class, 'offer'])->whereNumber('job')->middleware('throttle:30,1');
            Route::post('jobs/{job}/withdraw', [ArtisanJobController::class, 'withdraw'])->whereNumber('job');
            Route::post('jobs/{job}/en-route', [ArtisanJobController::class, 'enRoute'])->whereNumber('job');
            Route::post('jobs/{job}/arrive', [ArtisanJobController::class, 'arrive'])->whereNumber('job');
            Route::post('jobs/{job}/final-price', [ArtisanJobController::class, 'finalPrice'])->whereNumber('job')->middleware('throttle:30,1');
            Route::post('jobs/{job}/start', [ArtisanJobController::class, 'start'])->whereNumber('job');
            Route::post('jobs/{job}/complete', [ArtisanJobController::class, 'complete'])->whereNumber('job');
            Route::post('jobs/{job}/cancel', [ArtisanJobController::class, 'cancel'])->whereNumber('job');
            Route::post('jobs/{job}/ride', [ArtisanJobController::class, 'requestRide'])->whereNumber('job')->middleware('throttle:10,1');
            Route::get('drivers', [ArtisanDriverController::class, 'index']);
            Route::get('drivers/search', [ArtisanDriverController::class, 'search'])->middleware('throttle:30,1');
            Route::post('drivers', [ArtisanDriverController::class, 'store']);
            Route::delete('drivers/{driver}', [ArtisanDriverController::class, 'destroy'])->whereNumber('driver');
        });

        // ---- Hôte Keur : annonces et réservations ----
        Route::prefix('hote')->middleware('role:hote')->group(function () {
            Route::get('dashboard', [HoteLodgingController::class, 'dashboard']);
            Route::get('lodgings', [HoteLodgingController::class, 'index']);
            Route::post('lodgings', [HoteLodgingController::class, 'store'])->middleware('throttle:20,1');
            Route::get('lodgings/{lodging}', [HoteLodgingController::class, 'show'])->whereNumber('lodging');
            Route::patch('lodgings/{lodging}', [HoteLodgingController::class, 'update'])->whereNumber('lodging');
            Route::patch('lodgings/{lodging}/availability', [HoteLodgingController::class, 'availability'])->whereNumber('lodging');
            Route::post('lodgings/{lodging}/photos', [HoteLodgingController::class, 'addPhoto'])->whereNumber('lodging')->middleware('throttle:60,1');
            Route::delete('lodgings/{lodging}/photos/{photo}', [HoteLodgingController::class, 'deletePhoto'])->whereNumber(['lodging', 'photo']);
            Route::get('bookings', [HoteLodgingController::class, 'bookings']);
            Route::get('bookings/{booking}', [HoteLodgingController::class, 'showBooking'])->whereNumber('booking');
            Route::post('bookings/{booking}/accept', [HoteLodgingController::class, 'accept'])->whereNumber('booking');
            Route::post('bookings/{booking}/reject', [HoteLodgingController::class, 'reject'])->whereNumber('booking');
            Route::post('bookings/{booking}/complete', [HoteLodgingController::class, 'complete'])->whereNumber('booking');
            Route::post('bookings/{booking}/cancel', [HoteLodgingController::class, 'cancel'])->whereNumber('booking');
        });

        // ---- Démarcheur : recherches de logement par zone ----
        Route::prefix('demarcheur')->middleware('role:demarcheur')->group(function () {
            Route::patch('availability', [ChangeurPresenceController::class, 'availability']);
            Route::get('dashboard', [DemarcheurHousingController::class, 'dashboard']);
            Route::get('requests/available', [DemarcheurHousingController::class, 'available']);
            Route::get('requests', [DemarcheurHousingController::class, 'index']);
            Route::post('requests/{housingRequest}/offer', [DemarcheurHousingController::class, 'offer'])->whereNumber('housingRequest')->middleware('throttle:30,1');
            Route::post('requests/{housingRequest}/complete', [DemarcheurHousingController::class, 'complete'])->whereNumber('housingRequest');
            Route::post('requests/{housingRequest}/cancel', [DemarcheurHousingController::class, 'cancel'])->whereNumber('housingRequest');
        });

        // ---- Allo Doctors : consultations par spécialité ----
        Route::prefix('medecin')->middleware('role:medecin')->group(function () {
            Route::patch('availability', [ChangeurPresenceController::class, 'availability']);
            Route::post('location', [MedecinConsultationController::class, 'location'])->middleware('throttle:30,1');
            Route::get('dashboard', [MedecinConsultationController::class, 'dashboard']);
            Route::get('consultations/available', [MedecinConsultationController::class, 'available']);
            Route::get('consultations', [MedecinConsultationController::class, 'index']);
            Route::post('consultations/{consultation}/offer', [MedecinConsultationController::class, 'offer'])->whereNumber('consultation')->middleware('throttle:30,1');
            Route::post('consultations/{consultation}/price', [MedecinConsultationController::class, 'price'])->whereNumber('consultation')->middleware('throttle:20,1');
            Route::post('consultations/{consultation}/complete', [MedecinConsultationController::class, 'complete'])->whereNumber('consultation');
            Route::post('consultations/{consultation}/cancel', [MedecinConsultationController::class, 'cancel'])->whereNumber('consultation');
        });

        // ---- Soins et beauté : prestations par spécialité ----
        Route::prefix('beaute')->middleware('role:beaute')->group(function () {
            Route::patch('availability', [ChangeurPresenceController::class, 'availability']);
            Route::get('dashboard', [BeauteBeautyController::class, 'dashboard']);
            Route::get('requests/available', [BeauteBeautyController::class, 'available']);
            Route::get('requests', [BeauteBeautyController::class, 'index']);
            Route::post('requests/{beautyRequest}/offer', [BeauteBeautyController::class, 'offer'])->whereNumber('beautyRequest')->middleware('throttle:30,1');
            Route::post('requests/{beautyRequest}/complete', [BeauteBeautyController::class, 'complete'])->whereNumber('beautyRequest');
            Route::post('requests/{beautyRequest}/cancel', [BeauteBeautyController::class, 'cancel'])->whereNumber('beautyRequest');
        });

        // ---- Changeur : transferts d'argent et appel d'un transporteur ----
        Route::prefix('changeur')->middleware('role:changeur')->group(function () {
            Route::patch('availability', [ChangeurPresenceController::class, 'availability']);
            Route::post('location', [ChangeurPresenceController::class, 'location'])->middleware('throttle:30,1');
            Route::get('transfer-pricing', [ChangeurTransferController::class, 'pricing']);
            Route::put('transfer-pricing', [ChangeurTransferController::class, 'updatePricing']);
            Route::get('dashboard', [ChangeurTransferController::class, 'dashboard']);
            Route::get('transfers/available', [ChangeurTransferController::class, 'available']);
            Route::get('transfers', [ChangeurTransferController::class, 'index']);
            Route::get('transfers/{transfer}', [ChangeurTransferController::class, 'show'])->whereNumber('transfer');
            Route::post('transfers/{transfer}/offer', [ChangeurTransferController::class, 'offer'])->whereNumber('transfer')->middleware('throttle:30,1');
            Route::post('transfers/{transfer}/withdraw', [ChangeurTransferController::class, 'withdraw'])->whereNumber('transfer');
            Route::post('transfers/{transfer}/meet', [ChangeurTransferController::class, 'meet'])->whereNumber('transfer');
            Route::post('transfers/{transfer}/complete', [ChangeurTransferController::class, 'complete'])->whereNumber('transfer');
            Route::post('transfers/{transfer}/cancel', [ChangeurTransferController::class, 'cancel'])->whereNumber('transfer');
            Route::post('transfers/{transfer}/ride', [ChangeurTransferController::class, 'requestRide'])->whereNumber('transfer')->middleware('throttle:10,1');
        });

        // ---- Transporteur : courses (dont l'acheminement d'un artisan chez un client) ----
        Route::prefix('driver')->middleware('role:driver,livreur')->group(function () {
            Route::patch('availability', [DriverPresenceController::class, 'availability']);
            Route::post('location', [DriverPresenceController::class, 'location'])->middleware('throttle:30,1');
            Route::get('dashboard', [DriverRideController::class, 'dashboard']);
            Route::get('rides/available', [DriverRideController::class, 'available']);
            Route::get('rides', [DriverRideController::class, 'index']);
            Route::get('rides/{ride}', [DriverRideController::class, 'show'])->whereNumber('ride');
            Route::post('rides/{ride}/accept', [DriverRideController::class, 'accept'])->whereNumber('ride');
            Route::post('rides/{ride}/offer', [DriverRideController::class, 'offer'])->whereNumber('ride');
            Route::post('rides/{ride}/decline', [DriverRideController::class, 'decline'])->whereNumber('ride');
            Route::post('rides/{ride}/pickup', [DriverRideController::class, 'pickup'])->whereNumber('ride');
            Route::post('rides/{ride}/complete', [DriverRideController::class, 'complete'])->whereNumber('ride');
            Route::post('rides/{ride}/cancel', [DriverRideController::class, 'cancel'])->whereNumber('ride');
        });

        // ---- Passager d'une course (artisan aujourd'hui, client demain) ----
        Route::middleware('role:artisan,client,driver,changeur')->group(function () {
            Route::get('rides/{ride}', [RideController::class, 'show'])->whereNumber('ride');
            Route::post('rides/{ride}/cancel', [RideController::class, 'cancel'])->whereNumber('ride');
            Route::get('rides/{ride}/tracking', [RideController::class, 'tracking'])->whereNumber('ride')->middleware('throttle:60,1');
        });

        // ---- Boutiquier / restaurateur : gerer son commerce de A a Z ----
        Route::prefix('merchant')->name('merchant.')->middleware('role:boutique,restaurant')->group(function () {
            Route::get('shop', [MerchantShopController::class, 'show']);
            Route::patch('shop', [MerchantShopController::class, 'update']);
            Route::post('shop/images/{type}', [MerchantShopController::class, 'uploadImage'])
                ->whereIn('type', ['logo', 'cover'])->middleware('throttle:30,1');
            Route::delete('shop/images/{type}', [MerchantShopController::class, 'deleteImage'])
                ->whereIn('type', ['logo', 'cover']);

            Route::get('products', [MerchantProductController::class, 'index']);
            Route::post('products', [MerchantProductController::class, 'store']);
            Route::get('products/{product}', [MerchantProductController::class, 'show'])->whereNumber('product');
            Route::patch('products/{product}', [MerchantProductController::class, 'update'])->whereNumber('product');
            Route::delete('products/{product}', [MerchantProductController::class, 'destroy'])->whereNumber('product');
            Route::patch('products/{product}/availability', [MerchantProductController::class, 'availability'])->whereNumber('product');
            Route::patch('products/{product}/stock', [MerchantProductController::class, 'stock'])->whereNumber('product');
            Route::post('products/{product}/images', [MerchantProductController::class, 'addImage'])
                ->whereNumber('product')->middleware('throttle:60,1');
            Route::delete('products/{product}/images/{image}', [MerchantProductController::class, 'deleteImage'])
                ->whereNumber(['product', 'image']);

            Route::get('coupons', [MerchantCouponController::class, 'index']);
            Route::post('coupons', [MerchantCouponController::class, 'store']);
            Route::patch('coupons/{coupon}', [MerchantCouponController::class, 'update'])->whereNumber('coupon');
            Route::delete('coupons/{coupon}', [MerchantCouponController::class, 'destroy'])->whereNumber('coupon');

            Route::get('orders', [MerchantOrderController::class, 'index']);
            Route::get('orders/{order}', [MerchantOrderController::class, 'show'])->whereNumber('order');
            Route::post('orders/{order}/dispatch', [MerchantOrderController::class, 'dispatch'])->whereNumber('order');

            Route::get('couriers', [MerchantCourierController::class, 'index']);
            Route::get('couriers/search', [MerchantCourierController::class, 'search'])->middleware('throttle:30,1');
            Route::post('couriers', [MerchantCourierController::class, 'store']);
            Route::delete('couriers/{livreur}', [MerchantCourierController::class, 'destroy'])->whereNumber('livreur');

            Route::post('orders/{order}/{action}', [MerchantOrderController::class, 'act'])
                ->whereNumber('order')->whereIn('action', ['accept', 'reject', 'prepare', 'ready', 'complete', 'cancel']);

            Route::get('stats', MerchantStatsController::class);
            Route::get('reviews', [MerchantReviewController::class, 'index']);
            Route::post('reviews/{review}/reply', [MerchantReviewController::class, 'reply'])->whereNumber('review');
        });

        // ---- Livreur : prendre et effectuer des courses ----
        Route::prefix('livreur')->middleware('role:livreur')->group(function () {
            Route::get('deliveries/available', [DeliveryController::class, 'available']);
            Route::get('deliveries', [DeliveryController::class, 'index']);
            Route::get('deliveries/{order}', [DeliveryController::class, 'show'])->whereNumber('order');
            Route::post('deliveries/{order}/accept', [DeliveryController::class, 'accept'])->whereNumber('order');
            Route::post('deliveries/{order}/decline', [DeliveryController::class, 'decline'])->whereNumber('order');
            Route::post('deliveries/{order}/release', [DeliveryController::class, 'release'])->whereNumber('order');
            Route::post('deliveries/{order}/pickup', [DeliveryController::class, 'pickup'])->whereNumber('order');
            Route::post('deliveries/{order}/deliver', [DeliveryController::class, 'deliver'])->whereNumber('order');
            Route::get('earnings', [DeliveryController::class, 'earnings']);
            Route::get('dashboard', [DeliveryController::class, 'dashboard']);
            Route::patch('availability', [DeliveryController::class, 'availability']);
            Route::post('location', LocationController::class)->middleware('throttle:30,1');
        });

        // Reserve au role admin.
        Route::prefix('admin')->middleware('role:admin')->group(function () {
            Route::post('downloads/{platform}', [AppReleaseController::class, 'store'])->whereIn('platform', ['android', 'ios']);
            Route::get('users', [AdminUserController::class, 'index']);
            Route::patch('users/{user}/status', [AdminUserController::class, 'updateStatus']);
            Route::patch('users/{user}/visibility', [AdminUserController::class, 'updateVisibility']);
        });
    });
});
