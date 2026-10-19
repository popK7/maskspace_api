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
}
