<?php

namespace App\Enums;

/**
 * Type de commerce : une boutique vend des produits, un restaurant vend des plats.
 * Les deux partagent le meme moteur (commandes, livraison, avis, coupons, suivi).
 */
enum ShopKind: string
{
    case Boutique = 'boutique';
    case Restaurant = 'restaurant';

    /** Rayons (boutique) ou cuisines (restaurant) proposes a la boutique elle-meme. */
    public function categories(): array
    {
        return match ($this) {
            self::Boutique => ['epicerie', 'marche', 'wax', 'beaute', 'artisanat', 'saveurs'],
            self::Restaurant => ['senegalaise', 'ivoirienne', 'nigeriane', 'congolaise', 'ethiopienne', 'camerounaise'],
        };
    }

    /** Categories possibles pour un produit / un plat. */
    public function productCategories(): array
    {
        return match ($this) {
            self::Boutique => $this->categories(),
            self::Restaurant => self::dishCategories(),
        };
    }

    /** Sections d'une carte, dans l'ordre d'un repas. */
    public static function dishCategories(): array
    {
        return ['entree', 'plat', 'accompagnement', 'dessert', 'boisson'];
    }

    /** Toutes les valeurs de `category` acceptees par le catalogue public (rayons + cuisines). */
    public static function allShopCategories(): array
    {
        return array_values(array_unique([...self::Boutique->categories(), ...self::Restaurant->categories()]));
    }

    public static function forRole(UserRole $role): ?self
    {
        return match ($role) {
            UserRole::Boutique => self::Boutique,
            UserRole::Restaurant => self::Restaurant,
            default => null,
        };
    }
}
