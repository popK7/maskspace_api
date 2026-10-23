<?php

namespace App\Enums;

enum TransferCurrency: string
{
    case Mad = 'MAD';
    case Eur = 'EUR';
    case Xof = 'XOF';
    case Xaf = 'XAF';
    case Usd = 'USD';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public static function destinationFor(string $country): string
    {
        return match ($country) {
            'senegal', 'cote_ivoire', 'mali', 'burkina', 'niger', 'benin', 'togo' => self::Xof->value,
            'cameroun', 'congo', 'gabon' => self::Xaf->value,
            'rdc' => 'CDF',
            'guinee' => 'GNF',
            'maroc' => self::Mad->value,
            'algerie' => 'DZD',
            'tunisie' => 'TND',
            'france', 'belgique', 'espagne' => self::Eur->value,
            default => throw new \InvalidArgumentException('Pays de destination non pris en charge.'),
        };
    }
}
