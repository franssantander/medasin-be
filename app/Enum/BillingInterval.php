<?php

namespace App\Enum;

use Carbon\CarbonImmutable;

enum BillingInterval: string
{
    case MONTH = 'month';

    public function endFrom(CarbonImmutable $start): CarbonImmutable
    {
        return match ($this) {
            self::MONTH => $start->addMonthNoOverflow(),
        };
    }
}
