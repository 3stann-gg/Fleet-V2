<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use InvalidArgumentException;
use UnexpectedValueException;

final class RouteDepartureContextService
{
    public const HOSPITAL_TIMEZONE = 'Asia/Manila';

    /** @param (Closure(): DateTimeInterface)|null $clock */
    public function __construct(private readonly ?Closure $clock = null) {}

    /**
     * The caller must supply schedule fields from an authorized reservation.
     *
     * @return array{mode: 'planned', depart_at: string, timezone: string, source: 'reservation_schedule', verification: 'requested_context_only'}
     */
    public function forPlannedDeparture(?string $scheduleDate, ?string $scheduleTime): array
    {
        if ($scheduleDate === null || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $scheduleDate) !== 1) {
            throw new InvalidArgumentException('The reservation schedule date must use Y-m-d format.');
        }

        [$year, $month, $day] = array_map('intval', explode('-', $scheduleDate));
        if (! checkdate($month, $day, $year)) {
            throw new InvalidArgumentException('The reservation schedule date must be a valid calendar date.');
        }

        if ($scheduleTime === null || preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?\z/', $scheduleTime) !== 1) {
            throw new InvalidArgumentException('The reservation schedule time must use H:i or H:i:s format.');
        }

        $normalizedTime = strlen($scheduleTime) === 5 ? $scheduleTime.':00' : $scheduleTime;
        $localDateTime = $scheduleDate.' '.$normalizedTime;
        $departure = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $localDateTime, self::HOSPITAL_TIMEZONE);

        if ($departure === null || $departure->format('Y-m-d H:i:s') !== $localDateTime) {
            throw new InvalidArgumentException('The reservation schedule cannot be represented in the hospital timezone.');
        }

        if ($departure->lessThan($this->clockInstant())) {
            throw new InvalidArgumentException('The planned departure must not be in the past.');
        }

        return $this->context('planned', $departure, 'reservation_schedule');
    }

    /**
     * @return array{mode: 'current', depart_at: string, timezone: string, source: 'server_clock', verification: 'requested_context_only'}
     */
    public function forCurrentNavigation(): array
    {
        return $this->context('current', $this->clockInstant(), 'server_clock');
    }

    private function clockInstant(): CarbonImmutable
    {
        $instant = $this->clock === null
            ? CarbonImmutable::now(self::HOSPITAL_TIMEZONE)
            : ($this->clock)();

        if (! $instant instanceof DateTimeInterface) {
            throw new UnexpectedValueException('The server clock must return a date-time value.');
        }

        return CarbonImmutable::instance($instant)->setTimezone(self::HOSPITAL_TIMEZONE);
    }

    /**
     * @param  'planned'|'current'  $mode
     * @param  'reservation_schedule'|'server_clock'  $source
     * @return array{mode: 'planned'|'current', depart_at: string, timezone: string, source: 'reservation_schedule'|'server_clock', verification: 'requested_context_only'}
     */
    private function context(string $mode, CarbonImmutable $departure, string $source): array
    {
        return [
            'mode' => $mode,
            'depart_at' => $departure->format(DateTimeInterface::RFC3339),
            'timezone' => self::HOSPITAL_TIMEZONE,
            'source' => $source,
            'verification' => 'requested_context_only',
        ];
    }
}
