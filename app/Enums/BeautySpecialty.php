<?php

namespace App\Enums;

enum BeautySpecialty: string
{
    case Coiffeur = 'coiffeur';
    case Barbier = 'barbier';
    case Tresses = 'tresses';
    case Manucure = 'manucure';
    case Pedicure = 'pedicure';
    case SoinVisage = 'soin_visage';
    case Epilation = 'epilation';
    case Maillot = 'maillot';
    case Maquillage = 'maquillage';
    case Massage = 'massage';
}
