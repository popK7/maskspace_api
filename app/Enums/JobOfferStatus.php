<?php

namespace App\Enums;

enum JobOfferStatus: string
{
    case Pending = 'pending';
    case Chosen = 'chosen';
    case Declined = 'declined';
    case Withdrawn = 'withdrawn';
}
