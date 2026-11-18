<?php

namespace App\Actions\Billing;

use App\Billing\Cost;
use App\Billing\Rates;
use App\Billing\TokenUsage;

final class CalculateCost
{
    /**
     * Bill a finished generation from its usage and the rates stored on that generation.
     * The user pays the provider cost plus 25%, rounded up to the next nanodollar.
     */
    public function __invoke(TokenUsage $usage, Rates $rates): Cost
    {
        $provider = $rates->cost($usage);

        return new Cost($provider, $provider->withMarkup());
    }
}
