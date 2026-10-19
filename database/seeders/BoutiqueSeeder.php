<?php

namespace Database\Seeders;

use App\Enums\CouponType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Shop;
use App\Models\User;
use App\Services\Media\ImageStorage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * Boutiques de demonstration (portage de boutique_mock.dart). Environnement local uniquement.
 * La boutique de boutique@transport.app est celle creee par DatabaseSeeder (completee ici).
 */
class BoutiqueSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        $week = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
        $hours = array_fill_keys($week, [['open' => '09:00', 'close' => '20:00']]);
        $hours['sun'] = [['open' => '10:00', 'close' => '14:00']];

        foreach ($this->shops() as $i => $s) {
            $owner = User::where('email', $s['email'])->first() ?? new User;
            $owner->forceFill([
                'name' => $s['owner'], 'email' => $s['email'], 'phone' => '+212 6 10 00 00 0'.$i,
                'password' => Hash::make('123456'), 'role' => UserRole::Boutique,
                'status' => UserStatus::Active, 'email_verified_at' => now(),
            ])->save();

            $shop = $owner->shop ?? new Shop(['owner_id' => $owner->id]);
            $shop->owner_id = $owner->id;
            $shop->fill([
                'name' => $s['name'], 'description' => $s['blurb'], 'categories' => [$s['category']],
                'phone' => $owner->phone, 'address' => $s['neighborhood'], 'neighborhood' => $s['neighborhood'],
                'city' => 'Casablanca', 'latitude' => $s['lat'], 'longitude' => $s['lng'],
                'opening_hours' => $hours, 'is_open' => true, 'is_active' => true,
                'supports_delivery' => true, 'supports_pickup' => true,
                'delivery_fee_cents' => 1800, 'free_delivery_threshold_cents' => 30000,
                'min_order_cents' => 2000, 'delivery_radius_km' => 15, 'prep_minutes' => $s['minutes'],
                'rating_avg' => $s['rating'], 'rating_count' => 0, 'orders_count' => $s['orders'],
            ]);
            $shop->save();

            foreach ($s['products'] as [$name, $origin, $unit, $dh, $desc, $photo]) {
                $product = $shop->products()->withTrashed()->firstOrNew(['name' => $name]);
                $product->fill([
                    'origin' => $origin, 'unit' => $unit, 'description' => $desc, 'category' => $s['category'],
                    'price_cents' => $dh * 100, 'track_stock' => true, 'stock_quantity' => 25,
                    'low_stock_threshold' => 5, 'is_active' => true,
                ])->save();
                $product->restore();

                // Photo de demo copiee sur le disque public ; une photo envoyee par le marchand n'est jamais ecrasee.
                $path = $this->seedImage($photo);
                $first = $product->images()->orderBy('position')->first();
                if ($first === null) {
                    $product->images()->create(['path' => $path, 'position' => 0]);
                } elseif (str_starts_with($first->path, 'http') || str_starts_with($first->path, 'seed/')) {
                    $first->update(['path' => $path]);
                }

                // Photos supplementaires de demo ({slug}-2.jpg, {slug}-3.jpg...) : montrent la galerie.
                foreach ([2, 3, 4] as $n) {
                    if (! is_file(database_path("seeders/images/{$photo}-{$n}.jpg"))) {
                        break;
                    }
                    $extra = $this->seedImage("{$photo}-{$n}");
                    $product->images()->firstOrCreate(['path' => $extra], ['position' => $n - 1]);
                }
            }

            // Vitrine : premiere photo produit comme couverture de la boutique (si aucune n'a ete envoyee).
            $cover = $shop->cover_path;
            if (! empty($s['products']) && (empty($cover) || str_starts_with($cover, 'http') || str_starts_with($cover, 'seed/'))) {
                $shop->forceFill(['cover_path' => $this->seedImage($s['products'][0][5])])->save();
            }

            $shop->coupons()->firstOrCreate(['code' => 'BIENVENUE10'], [
                'type' => CouponType::Percent, 'value' => 10, 'min_order_cents' => 5000, 'is_active' => true,
            ]);
        }
    }

    /**
     * Copie database/seeders/images/{slug}.jpg vers le disque public (seed/{slug}.jpg) et renvoie le chemin.
     * Photos : Wikimedia Commons (licences libres), voir database/seeders/images/CREDITS.md.
     */
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

    /** @return list<array<string, mixed>> */
    private function shops(): array
    {
        return [
            [
                'email' => 'boutique@transport.app', 'owner' => 'Fatou Diallo', 'name' => 'Épicerie Mama Awa',
                'neighborhood' => 'Hay Hassani, Casablanca', 'category' => 'epicerie', 'rating' => 4.8, 'orders' => 312,
                'minutes' => 28, 'lat' => 33.5724, 'lng' => -7.6611,
                'blurb' => 'Produits du Golfe de Guinée : attiéké, gari, huile de palme.',
                'products' => [
                    ['Attiéké', 'Côte d’Ivoire', 'sachet 500 g', 28, 'Semoule de manioc fermentée, prête à réchauffer.', 'attieke'],
                    ['Gari', 'Bénin', 'sachet 1 kg', 32, 'Gari croquant pour l’accompagnement ou le déjeuner.', 'gari'],
                    ['Huile de palme rouge', 'Ghana', 'bouteille 1 L', 45, 'Huile de palme artisanale, goût fruité.', 'huile-de-palme'],
                    ['Feuilles de manioc', 'Congo', 'botte 400 g', 22, 'Feuilles fraîches pour pondu ou saka-saka.', 'feuilles-de-manioc'],
                    ['Piment séché', 'Sénégal', 'sachet 100 g', 18, 'Piment fort séché au soleil.', 'piment-seche'],
                ],
            ],
            [
                'email' => 'plantain@transport.app', 'owner' => 'Kwame Mensah', 'name' => 'Marché Plantain Casa',
                'neighborhood' => 'Derb Sultan, Casablanca', 'category' => 'marche', 'rating' => 4.6, 'orders' => 198,
                'minutes' => 35, 'lat' => 33.5758, 'lng' => -7.6054,
                'blurb' => 'Fruits tropicaux du jour : plantain, igname, mangue, okra.',
                'products' => [
                    ['Régime de plantains', 'Cameroun', '6 fruits', 38, 'Plantains mûrs, idéals à frire ou à braiser.', 'regime-de-plantains'],
                    ['Igname', 'Nigeria', '1 kg', 26, 'Tubercules fermes, calibre marché.', 'igname'],
                    ['Mangues kent', 'Mali', 'filet 1 kg', 30, 'Mangues kent juteuses, arrivage du matin.', 'mangues-kent'],
                    ['Gombo frais', 'Burkina Faso', 'barquette 400 g', 16, 'Gombos croquants pour sauces et ragoûts.', 'gombo-frais'],
                ],
            ],
            [
                'email' => 'wax@transport.app', 'owner' => 'Aïcha Touré', 'name' => 'Maison du Wax',
                'neighborhood' => 'Maârif, Casablanca', 'category' => 'wax', 'rating' => 4.9, 'orders' => 154,
                'minutes' => 40, 'lat' => 33.5868, 'lng' => -7.6322,
                'blurb' => 'Pagnes super wax, bazin et boubous cousus à la demande.',
                'products' => [
                    ['Pagne super wax', 'Côte d’Ivoire', '6 yards', 280, 'Wax hollandais, motifs saison, 6 yards.', 'pagne-super-wax'],
                    ['Bazin riche', 'Mali', '5 yards', 420, 'Bazin damassé, tombé lourd, prêt à coudre.', 'bazin-riche'],
                    ['Boubou brodé', 'Sénégal', 'pièce', 650, 'Boubou brodé main, taille unique généreuse.', 'boubou-brode'],
                ],
            ],
            [
                'email' => 'karite@transport.app', 'owner' => 'Mariam Coulibaly', 'name' => 'Karité d’Afrique',
                'neighborhood' => 'Anfa, Casablanca', 'category' => 'beaute', 'rating' => 4.7, 'orders' => 267,
                'minutes' => 32, 'lat' => 33.5956, 'lng' => -7.6774,
                'blurb' => 'Beurre de karité, savon noir et huiles pressées à froid.',
                'products' => [
                    ['Beurre de karité brut', 'Burkina Faso', 'pot 250 ml', 55, 'Karité non raffiné, barre et peau.', 'beurre-de-karite'],
                    ['Savon noir', 'Ghana', 'pot 200 g', 35, 'Savon noir artisanal, gommage doux.', 'savon-noir'],
                    ['Huile de coco', 'Côte d’Ivoire', 'bouteille 250 ml', 42, 'Huile de coco pressée à froid.', 'huile-de-coco'],
                    ['Huile de moringa', 'Sénégal', 'flacon 50 ml', 68, 'Huile précieuse, visage et cheveux.', 'huile-de-moringa'],
                ],
            ],
            [
                'email' => 'nattes@transport.app', 'owner' => 'Youssef El Amrani', 'name' => 'Atelier Panier & Nattes',
                'neighborhood' => 'Habous, Casablanca', 'category' => 'artisanat', 'rating' => 4.5, 'orders' => 89,
                'minutes' => 45, 'lat' => 33.5731, 'lng' => -7.5989,
                'blurb' => 'Paniers, nattes et objets tressés du Sahel et du Maghreb.',
                'products' => [
                    ['Panier tressé', 'Sénégal', 'pièce', 120, 'Panier en rônier, anse solide.', 'panier-tresse'],
                    ['Natte colorée', 'Mauritanie', '1,80 m', 190, 'Natte tressée, motifs sahéliens.', 'natte-coloree'],
                    ['Calebasse décorée', 'Mali', 'pièce', 85, 'Calebasse gravée, pièce unique.', 'calebasse-decoree'],
                ],
            ],
            [
                'email' => 'sahel@transport.app', 'owner' => 'Ibrahim Traoré', 'name' => 'Saveurs du Sahel',
                'neighborhood' => 'Roches Noires, Casablanca', 'category' => 'saveurs', 'rating' => 4.8, 'orders' => 221,
                'minutes' => 30, 'lat' => 33.6042, 'lng' => -7.5688,
                'blurb' => 'Bissap, gingembre, mil et condiments du Sahel.',
                'products' => [
                    ['Fleurs de bissap', 'Sénégal', 'sachet 200 g', 24, 'Hibiscus séché pour bissap glacé.', 'fleurs-de-bissap'],
                    ['Gingembre séché', 'Nigeria', 'sachet 150 g', 20, 'Gingembre fort, infusion ou cuisine.', 'gingembre-seche'],
                    ['Farine de mil', 'Mali', '1 kg', 28, 'Farine de mil pour bouillie et galettes.', 'farine-de-mil'],
                    ['Soumbala', 'Burkina Faso', 'pot 100 g', 36, 'Condiment fermenté, goût profond.', 'soumbala'],
                ],
            ],
        ];
    }
}
