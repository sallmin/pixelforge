<?php

namespace App\Billing;

enum Entry: string
{
    case Purchase = 'purchase';
    case Hold = 'hold';
    case Charge = 'charge';
    case Release = 'release';
}
