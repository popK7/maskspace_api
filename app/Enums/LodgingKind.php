<?php

namespace App\Enums;

enum LodgingKind: string
{
    case Appartement = 'appartement';
    case Chambre = 'chambre';
    case Studio = 'studio';
    case Villa = 'villa';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $k) => $k->value, self::cases());
    }
}
