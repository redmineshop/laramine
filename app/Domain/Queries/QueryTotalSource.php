<?php

namespace App\Domain\Queries;

/**
 * Where a query total reads its number.
 */
enum QueryTotalSource: string
{
    case EstimatedHours = 'estimated_hours';
    case EstimatedRemainingHours = 'estimated_remaining_hours';
    case SpentHours = 'spent_hours';
    case CustomInt = 'custom_int';
    case CustomFloat = 'custom_float';
}
