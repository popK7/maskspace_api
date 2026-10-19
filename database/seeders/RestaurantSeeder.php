<?php

namespace Database\Seeders;

use App\Enums\CouponType;
use App\Enums\ShopKind;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Shop;
use App\Models\User;
use App\Services\Media\ImageStorage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * Restaurants de démonstration. Local et production.
 * Le restaurant de restaurant@transport.app est celui du compte créé par DatabaseSeeder (complété ici).
 * Les photos réutilisent celles des produits du BoutiqueSeeder (ingrédients), faute de photos de plats libres de droits.
 */
class RestaurantSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'production'])) {
            return;
        }

        $days = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        $hours = array_fill_keys($days, [['open' => '10:00', 'close' => '23:30']]);

        foreach ($this->restaurants() as $i => $r) {
            $owner = User::where('email', $r['email'])->first() ?? new User;
            $owner->forceFill([
                'name' => $r['owner'], 'email' => $r['email'], 'phone' => '+212 6 20 00 00 0'.$i,
                'password' => Hash::make('123456'), 'role' => UserRole::Restaurant,
                'status' => UserStatus::Active, 'email_verified_at' => now(),
            ])->save();
            $owner->profile()->updateOrCreate([], ['shop_name' => $r['name'], 'specialties' => [$r['cuisine']]]);

            $shop = $owner->shop ?? new Shop(['owner_id' => $owner->id]);
            $shop->owner_id = $owner->id;
            $shop->fill([
                'kind' => ShopKind::Restaurant, 'name' => $r['name'], 'description' => $r['blurb'],
                'categories' => [$r['cuisine']], 'phone' => $owner->phone,
                'address' => $r['neighborhood'], 'neighborhood' => $r['neighborhood'], 'city' => 'Casablanca',
                'latitude' => $r['lat'], 'longitude' => $r['lng'],
                'opening_hours' => $hours, 'is_open' => true, 'is_active' => true,
                'supports_delivery' => true, 'supports_pickup' => true,
                'delivery_fee_cents' => 1500, 'free_delivery_threshold_cents' => 15000,
                'min_order_cents' => 4000, 'delivery_radius_km' => 12, 'prep_minutes' => $r['minutes'],
                'rating_avg' => $r['rating'], 'rating_count' => 0, 'orders_count' => $r['orders'],
            ]);
            $shop->save();

            foreach ($r['dishes'] as [$name, $category, $dh, $desc, $photo, $featured, $spice, $veg, $prep]) {
                $dish = $shop->products()->withTrashed()->firstOrNew(['name' => $name]);
                $dish->fill([
                    'description' => $desc, 'category' => $category, 'price_cents' => $dh * 100,
                    'track_stock' => false, 'stock_quantity' => 0, 'is_active' => true,
                    'is_featured' => $featured, 'spice_level' => $spice, 'is_vegetarian' => $veg, 'prep_minutes' => $prep,
                ])->save();
                $dish->restore();

                if ($photo !== null) {
                    $path = $this->seedImage($photo);
                    $first = $dish->images()->orderBy('position')->first();
                    if ($first === null) {
                        $dish->images()->create(['path' => $path, 'position' => 0]);
                    } elseif (str_starts_with($first->path, 'http') || str_starts_with($first->path, 'seed/')) {
                        $first->update(['path' => $path]);
                    }
                }
            }

            // Vitrine : la photo du plat du jour sert de couverture (si aucune n'a été envoyée).
            $cover = $shop->cover_path;
            $hero = collect($r['dishes'])->first(fn ($d) => $d[5] && $d[4] !== null) ?? collect($r['dishes'])->first(fn ($d) => $d[4] !== null);
            if ($hero && (empty($cover) || str_starts_with($cover, 'http') || str_starts_with($cover, 'seed/'))) {
                $shop->forceFill(['cover_path' => $this->seedImage($hero[4])])->save();
            }

            $shop->coupons()->firstOrCreate(['code' => 'BIENVENUE10'], [
                'type' => CouponType::Percent, 'value' => 10, 'min_order_cents' => 6000, 'is_active' => true,
            ]);
        }
    }

    private function seedImage(string $slug): string
    {
        $path = "seed/{$slug}.jpg";
        $disk = Storage::disk('public');

        if (! $disk->exists($path)) {
            $source = database_path("seeders/images/{$slug}.jpg");
            if (is_file($source)) {
                $disk->put($path, file_get_contents($source));
            }
        }

        app(ImageStorage::class)->ensureThumbnail($path);

        return $path;
    }

    /**
     * Plats : [nom, catégorie, prix DH, description, photo|null, plat du jour, piment 0-3, végétarien, minutes].
     *
     * @return list<array<string, mixed>>
     */
    private function restaurants(): array
    {
        return [
            [
                'email' => 'restaurant@transport.app', 'owner' => 'Adjoua Kouassi', 'name' => 'Chez Tantie',
                'cuisine' => 'ivoirienne', 'neighborhood' => 'Maârif, Casablanca', 'lat' => 33.5879, 'lng' => -7.6338,
                'rating' => 4.8, 'orders' => 286, 'minutes' => 25,
                'blurb' => 'Cuisine ivoirienne maison : poulet braisé, attiéké, alloco et sauces mijotées.',
                'dishes' => [
                    ['Poulet braisé & attiéké', 'plat', 65, 'Demi-poulet mariné et braisé, attiéké, oignons et piment frais.', 'attieke', true, 2, false, 30],
                    ['Kédjenou de poulet', 'plat', 70, 'Poulet mijoté à l’étouffée avec légumes et épices, en daba.', null, false, 2, false, 40],
                    ['Sauce gombo & riz', 'plat', 52, 'Sauce gombo onctueuse au poisson fumé, riz blanc.', 'gombo-frais', false, 1, false, 30],
                    ['Garba', 'plat', 38, 'Le classique d’Abidjan : attiéké, thon frit, piment et oignons.', 'attieke-2', false, 1, false, 15],
                    ['Alloco', 'accompagnement', 22, 'Bananes plantains mûres frites, sauce pimentée.', 'regime-de-plantains', false, 1, true, 12],
                    ['Igname frite', 'accompagnement', 24, 'Bâtonnets d’igname dorés, mayonnaise pimentée.', 'igname', false, 0, true, 12],
                    ['Beignets de banane', 'dessert', 18, 'Beignets moelleux à la banane, saupoudrés de sucre.', null, false, 0, true, 10],
                    ['Salade de mangues', 'dessert', 20, 'Mangues kent, citron vert et menthe fraîche.', 'mangues-kent', false, 0, true, 5],
                    ['Jus de bissap', 'boisson', 15, 'Hibiscus glacé, menthe et une pointe de vanille.', 'fleurs-de-bissap', false, 0, true, 3],
                    ['Jus de gingembre', 'boisson', 15, 'Gingembre pressé, citron et ananas, bien frais.', 'gingembre-seche', false, 2, true, 3],
                ],
            ],
            [
                'email' => 'teranga@transport.app', 'owner' => 'Mamadou Sow', 'name' => 'Teranga Casa',
                'cuisine' => 'senegalaise', 'neighborhood' => 'Gauthier, Casablanca', 'lat' => 33.5902, 'lng' => -7.6269,
                'rating' => 4.7, 'orders' => 341, 'minutes' => 30,
                'blurb' => 'Thieboudienne, yassa et mafé : l’hospitalité sénégalaise à table.',
                'dishes' => [
                    ['Thieboudienne', 'plat', 68, 'Riz au poisson, légumes du marché et tamarin, plat national.', 'huile-de-palme', true, 1, false, 35],
                    ['Poulet yassa', 'plat', 58, 'Poulet mariné aux oignons et citron, riz parfumé.', null, false, 1, false, 30],
                    ['Mafé de bœuf', 'plat', 62, 'Bœuf mijoté dans une sauce onctueuse à l’arachide.', null, false, 1, false, 35],
                    ['Dibi grillé', 'plat', 55, 'Agneau grillé au feu de bois, moutarde et oignons.', null, false, 2, false, 25],
                    ['Accras de poisson', 'entree', 28, 'Beignets de poisson croustillants, sauce piment.', null, false, 2, false, 15],
                    ['Pastels', 'entree', 26, 'Petits chaussons farcis au poisson, frits minute.', 'piment-seche', false, 2, false, 15],
                    ['Thiakry', 'dessert', 20, 'Couscous de mil, crème et vanille, servi frais.', 'farine-de-mil', false, 0, true, 5],
                    ['Bissap glacé', 'boisson', 15, 'Fleurs d’hibiscus infusées, sucre de canne.', 'fleurs-de-bissap-2', false, 0, true, 3],
                ],
            ],
            [
                'email' => 'ndole@transport.app', 'owner' => 'Rose Mbarga', 'name' => 'Mama Ndolé',
                'cuisine' => 'camerounaise', 'neighborhood' => 'Racine, Casablanca', 'lat' => 33.5811, 'lng' => -7.6455,
                'rating' => 4.6, 'orders' => 174, 'minutes' => 35,
                'blurb' => 'Ndolé aux arachides, poulet DG et plantains : le Cameroun comme à Douala.',
                'dishes' => [
                    ['Ndolé aux crevettes', 'plat', 72, 'Feuilles amères mijotées aux arachides et crevettes, plantain mûr.', 'feuilles-de-manioc', true, 1, false, 40],
                    ['Poulet DG', 'plat', 68, 'Poulet sauté aux plantains, carottes et haricots verts.', 'regime-de-plantains-2', false, 1, false, 30],
                    ['Eru & waterfleaf', 'plat', 60, 'Légumes verts mijotés, viande fumée, accompagnés de gari.', 'gari', false, 2, false, 35],
                    ['Beignets haricots', 'entree', 20, 'Beignets de haricots épicés, croustillants.', null, false, 1, true, 12],
                    ['Gingembre glacé', 'boisson', 15, 'Boisson de gingembre relevée, bien fraîche.', 'gingembre-seche-2', false, 2, true, 3],
                ],
            ],
            [
                'email' => 'injera@transport.app', 'owner' => 'Selam Bekele', 'name' => 'Addis Injera',
                'cuisine' => 'ethiopienne', 'neighborhood' => 'Habous, Casablanca', 'lat' => 33.5736, 'lng' => -7.6012,
                'rating' => 4.9, 'orders' => 129, 'minutes' => 28,
                'blurb' => 'Injera maison, doro wat et plateaux végétariens à partager.',
                'dishes' => [
                    ['Doro wat', 'plat', 74, 'Poulet mijoté au berbéré, œuf dur, injera tiède.', 'piment-seche-2', true, 3, false, 35],
                    ['Plateau végétarien', 'plat', 66, 'Lentilles, pois chiches, légumes et injera, à partager.', null, false, 1, true, 25],
                    ['Tibs d’agneau', 'plat', 78, 'Agneau sauté aux oignons et romarin.', null, false, 2, false, 25],
                    ['Café éthiopien', 'boisson', 16, 'Café torréfié et servi en cérémonie.', null, false, 0, true, 8],
                ],
            ],
        ];
    }
}
