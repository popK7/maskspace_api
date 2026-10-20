<?php

namespace App\Enums;

enum MedicalSpecialty: string
{
    case Generaliste = 'generaliste';
    case Cardiologue = 'cardiologue';
    case Pediatre = 'pediatre';
    case Dermatologue = 'dermatologue';
    case Gynecologue = 'gynecologue';
    case Ophtalmologue = 'ophtalmologue';
    case Orl = 'orl';
    case Dentiste = 'dentiste';
    case Psychiatre = 'psychiatre';
    case Kinesitherapeute = 'kinesitherapeute';
}
