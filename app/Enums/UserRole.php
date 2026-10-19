<?php

namespace App\Enums;

/**
 * Roles de la plateforme. Les valeurs correspondent a `UserRole.name` cote Flutter.
 */
enum UserRole: string
{
    case Client = 'client';
    case Driver = 'driver';
    case Artisan = 'artisan';
    case Livreur = 'livreur';
    case Boutique = 'boutique';
    case Restaurant = 'restaurant';
    case Changeur = 'changeur';
    case Hote = 'hote';
    case Demarcheur = 'demarcheur';
    case Admin = 'admin';

    /**
     * Roles qu'un utilisateur peut choisir lui-meme a l'inscription.
     * `admin` n'en fait volontairement pas partie (cree via seeder / commande artisan).
     *
     * @return list<self>
     */
    public static function selfRegistrable(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $role) => $role !== self::Admin,
        ));
    }

    /** @return list<string> */
    public static function selfRegistrableValues(): array
    {
        return array_map(fn (self $r) => $r->value, self::selfRegistrable());
    }

    /** Vehicule + immatriculation obligatoires. */
    public function requiresVehicle(): bool
    {
        return in_array($this, [self::Driver, self::Livreur], true);
    }

    /** Nom de commerce obligatoire. */
    public function requiresShopName(): bool
    {
        return in_array($this, [self::Boutique, self::Restaurant], true);
    }

    /**
     * Valeurs autorisees pour `specialties` (metiers / rayons / cuisines),
     * ou null si le role n'a pas de specialites.
     *
     * @return list<string>|null
     */
    public function allowedSpecialties(): ?array
    {
        return match ($this) {
            self::Artisan => [
                'plomberie', 'maconnerie', 'electricite', 'jardinage', 'piscine', 'carrelage',
                'nettoyage', 'peinture', 'menuiserie', 'climatisation', 'serrurerie', 'toiture',
            ],
            self::Boutique => ['epicerie', 'marche', 'wax', 'beaute', 'artisanat', 'saveurs'],
            self::Restaurant => [
                'senegalaise', 'ivoirienne', 'nigeriane', 'congolaise', 'ethiopienne', 'camerounaise',
            ],
            self::Changeur => [
                'senegal', 'cote_ivoire', 'mali', 'burkina', 'niger', 'cameroun', 'congo', 'rdc',
                'gabon', 'guinee', 'benin', 'togo', 'maroc', 'algerie', 'tunisie',
                'france', 'belgique', 'espagne',
            ],
            self::Hote => ['appartement', 'chambre', 'studio', 'villa'],
            self::Demarcheur => [
                'gironde', 'maarif', 'gauthier', 'racine', 'anfa', 'ain_diab',
                'habous', 'bourgogne', 'oasis', 'sidi_maarouf',
            ],
            default => null,
        };
    }
}
