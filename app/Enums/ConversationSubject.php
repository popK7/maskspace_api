<?php

namespace App\Enums;

enum ConversationSubject: string
{
    case Order = 'order';
    case Job = 'job';
    case Ride = 'ride';
    case Transfer = 'transfer';
    case Booking = 'booking';
    case Housing = 'housing';
}
