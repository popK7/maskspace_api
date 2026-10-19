<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Lodging;
use App\Models\User;
use Illuminate\Database\Seeder;

class KeurSeeder extends Seeder
{
    public function run(): void
    {
        $host = User::where('email', 'hote@transport.app')->where('role', UserRole::Hote)->first();
        if (! $host) {
            return;
        }

        $listings = [
            [
                'kind' => 'appartement',
                'title' => 'Appartement lumineux à Maârif',
                'description' => 'Trois pièces, salon ouvert, cuisine équipée. Idéal pour une famille ou un séjour de quelques nuits à Casablanca.',
                'address' => 'Rue Ibnou Katir, Maârif, Casablanca',
                'neighborhood' => 'Maârif',
                'guests' => 4,
                'bedrooms' => 2,
                'beds' => 3,
                'bathrooms' => 1,
                'price_cents' => 45000,
                'amenities' => ['wifi', 'clim', 'cuisine', 'tv', 'balcon'],
                'photos' => [
                    'https://images.unsplash.com/photo-1502672260266-1c1ef2d93688?auto=format&fit=crop&w=1400&q=80',
                    'https://images.unsplash.com/photo-1560448204-e02f11c3d0e2?auto=format&fit=crop&w=1400&q=80',
                    'https://images.unsplash.com/photo-1554995207-c18c3d79d3b2?auto=format&fit=crop&w=1400&q=80',
                    'https://images.unsplash.com/photo-1484154216632-9c00e8d3b5b5?auto=format&fit=crop&w=1400&q=80',
                ],
            ],
            [
                'kind' => 'chambre',
                'title' => 'Chambre calme chez l’hôte — Gauthier',
                'description' => 'Chambre indépendante, lit double, salle d’eau privative. Petit-déjeuner possible. Quartier central.',
                'address' => 'Boulevard d’Anfa, Gauthier, Casablanca',
                'neighborhood' => 'Gauthier',
                'guests' => 2,
                'bedrooms' => 1,
                'beds' => 1,
                'bathrooms' => 1,
                'price_cents' => 18000,
                'amenities' => ['wifi', 'clim', 'eau'],
                'photos' => [
                    'https://images.unsplash.com/photo-1505693416388-ac5ce068fe85?auto=format&fit=crop&w=1400&q=80',
                    'https://images.unsplash.com/photo-1522771739844-6a9f6d5f14af?auto=format&fit=crop&w=1400&q=80',
                    'https://images.unsplash.com/photo-1540518614846-7eded433c457?auto=format&fit=crop&w=1400&q=80',
                ],
            ],
            [
                'kind' => 'studio',
                'title' => 'Studio meublé Racine',
                'description' => 'Studio tout équipé, proche des commerces. Entrée indépendante, idéal pour un déplacement pro.',
                'address' => 'Rue Baht, Racine, Casablanca',
                'neighborhood' => 'Racine',
                'guests' => 2,
                'bedrooms' => 0,
                'beds' => 1,
                'bathrooms' => 1,
                'price_cents' => 28000,
                'amenities' => ['wifi', 'cuisine', 'tv', 'parking'],
                'photos' => [
                    'https://images.unsplash.com/photo-1522708323590-d24dbb6b0267?auto=format&fit=crop&w=1400&q=80',
                    'https://images.unsplash.com/photo-1493809842364-78817add7ffb?auto=format&fit=crop&w=1400&q=80',
                    'https://images.unsplash.com/photo-1560185127-6ed189bf02f4?auto=format&fit=crop&w=1400&q=80',
                ],
            ],
            [
                'kind' => 'villa',
                'title' => 'Villa avec jardin — Anfa',
                'description' => 'Villa familiale, grand salon, jardin, trois chambres. Location au mois, idéale pour une installation à Casablanca.',
                'address' => 'Boulevard de la Corniche, Anfa, Casablanca',
                'neighborhood' => 'Anfa',
                'guests' => 8,
                'bedrooms' => 3,
                'beds' => 5,
                'bathrooms' => 2,
                'price_cents' => 1200000,
                'billing' => 'month',
                'amenities' => ['wifi', 'clim', 'cuisine', 'parking', 'jardin', 'gardien'],
                'photos' => [
                    'https://images.unsplash.com/photo-1613490493576-7fde63acd811?auto=format&fit=crop&w=1400&q=80',
                    'https://images.unsplash.com/photo-1600596542815-ffad4c1539a9?auto=format&fit=crop&w=1400&q=80',
                    'https://images.unsplash.com/photo-1600607687939-ce8a6c25118c?auto=format&fit=crop&w=1400&q=80',
                    'https://images.unsplash.com/photo-1600585154340-be6161a56a0c?auto=format&fit=crop&w=1400&q=80',
                ],
            ],
        ];

        foreach ($listings as $row) {
            $photos = $row['photos'];
            unset($row['photos']);
            $lodging = Lodging::updateOrCreate(
                ['host_id' => $host->id, 'title' => $row['title']],
                [
                    ...$row,
                    'city' => 'Casablanca',
                    'latitude' => 33.5896,
                    'longitude' => -7.6033,
                    'is_active' => true,
                ],
            );
            $lodging->photos()->delete();
            foreach ($photos as $i => $url) {
                $lodging->photos()->create(['path' => $url, 'position' => $i + 1]);
            }
        }
    }
}
