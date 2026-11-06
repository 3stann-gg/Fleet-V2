<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class PreventiveMaintenanceRecurrenceCalculator
{
    public const FREQUENCY_WEEKLY = 'weekly';

    public const FREQUENCY_MONTHLY = 'monthly';

    public function calculateNextDueDate(
        CarbonImmutable $completionDate,
        string $frequency,
        int $interval = 1,
        bool $preserveMonthEnd = false,
    ): CarbonImmutable {
        if ($interval !== 1) {
            throw new InvalidArgumentException(
                'The preventive maintenance recurrence interval must be exactly 1.'
            );
        }

        if (! in_array($frequency, [self::FREQUENCY_WEEKLY, self::FREQUENCY_MONTHLY], true)) {
            throw new InvalidArgumentException(
                'The preventive maintenance recurrence frequency must be weekly or monthly.'
            );
        }

        if ($frequency === self::FREQUENCY_WEEKLY && $preserveMonthEnd) {
            throw new InvalidArgumentException(
                'Month-end preservation is only supported for monthly recurrence.'
            );
        }

        $normalizedCompletionDate = $completionDate->startOfDay();

        if ($frequency === self::FREQUENCY_WEEKLY) {
            return $normalizedCompletionDate
                ->addWeek()
                ->startOfDay();
        }

        $nextDueDate = $normalizedCompletionDate->addMonthNoOverflow();

        if ($preserveMonthEnd) {
            $nextDueDate = $nextDueDate->endOfMonth();
        }

        return $nextDueDate->startOfDay();
    }
}
