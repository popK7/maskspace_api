<?php

namespace App\Enums;

enum JobFinalPriceStatus: string
{
    case NotSubmitted = 'not_submitted';
    case Pending = 'pending';
    case Accepted = 'accepted';
}
