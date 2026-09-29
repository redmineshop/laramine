<?php

namespace App\Domain\Queries;

use App\Models\User;
use App\Models\UserPreference;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Exception;

/**
 * Inclusive calendar ranges for the shipped relative date operators.
 *
 * Weeks are Monday through Sunday. The anchor day is the user's
 * `user_preferences.time_zone` when it is a valid zone, otherwise the
 * application timezone. Datetime columns are compared in the application
 * timezone after that calendar day is converted.
 */
final class DateWindow
{
    /**
     * Relative operators that take no value.
     *
     * @var list<string>
     */
    public const CLOSED_RELATIVE = ['t', 'ld', 'w', 'lw', 'm', 'lm', 'y', 'nd', 'nw', 'nm', 'l2w'];

    /**
     * Operators whose single value is a non-negative day count.
     *
     * @var list<string>
     */
    public const OFFSET_OPERATORS = ['<t+', '>t+', '><t+', 't+', '>t-', '<t-', '><t-', 't-'];

    public function __construct(private readonly CarbonImmutable $today) {}

    public static function forUser(?User $user, ?CarbonImmutable $now = null): self
    {
        $zone = self::zone($user);
        $moment = $now ?? CarbonImmutable::now($zone);

        return new self($moment->timezone($zone)->startOfDay());
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function dates(string $operator): array
    {
        $today = $this->today;

        return match ($operator) {
            't' => [$today->toDateString(), $today->toDateString()],
            'ld' => self::span($today->subDay(), $today->subDay()),
            'w' => self::span(
                $today->startOfWeek(CarbonImmutable::MONDAY),
                $today->endOfWeek(CarbonImmutable::SUNDAY),
            ),
            'lw' => self::previousWeek($today),
            'm' => self::span($today->startOfMonth(), $today->endOfMonth()),
            'lm' => self::previousMonth($today),
            'y' => self::span($today->startOfYear(), $today->endOfYear()),
            default => throw new QueryValidationException('Operator '.$operator.' is not a relative date.'),
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function datetimes(string $operator): array
    {
        [$from, $to] = $this->dates($operator);
        $zone = $this->today->getTimezone();
        $app = self::applicationZone();
        $start = CarbonImmutable::parse($from.' 00:00:00', $zone)->timezone($app);
        $end = CarbonImmutable::parse($to.' 23:59:59', $zone)->timezone($app);

        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
    }

    /**
     * Calendar window for a relative operator. `$days` is used by the offset operators.
     * An inverted window (`><t+` 0, `><t-` 0) is empty and matches nothing.
     *
     * Readings, with T = the anchor date: `nd` is T+1; `nw` is the next Monday–Sunday;
     * `nm` is the next calendar month; `l2w` is the two calendar weeks before this week;
     * `t+` / `t-` are exactly T±N; `<t+` is on or before T+N−1; `>t+` is on or after T+N+1;
     * `><t+` is T+1 through T+N; `>t-` is T−N through T; `<t-` is on or before T−N−1;
     * `><t-` is T−N through T−1.
     */
    public function calendarBound(string $operator, int $days = 0): DateBound
    {
        $today = $this->today;

        return match ($operator) {
            't', 'ld', 'w', 'lw', 'm', 'lm', 'y' => self::closed(...$this->dates($operator)),
            'nd' => self::closedDay($today->addDay()),
            'nw' => self::closed(
                ...self::span(
                    $today->startOfWeek(CarbonImmutable::MONDAY)->addWeek(),
                    $today->startOfWeek(CarbonImmutable::MONDAY)->addWeek()->endOfWeek(CarbonImmutable::SUNDAY),
                ),
            ),
            'nm' => self::closed(...self::nextMonth($today)),
            'l2w' => self::closed(
                ...self::span(
                    $today->startOfWeek(CarbonImmutable::MONDAY)->subWeeks(2),
                    $today->startOfWeek(CarbonImmutable::MONDAY)->subDay(),
                ),
            ),
            't+' => self::closedDay($today->addDays($days)),
            't-' => self::closedDay($today->subDays($days)),
            '<t+' => new DateBound(null, $today->addDays($days)->subDay()->toDateString()),
            '>t+' => new DateBound($today->addDays($days)->addDay()->toDateString(), null),
            '><t+' => self::between($today->addDay(), $today->addDays($days)),
            '>t-' => self::between($today->subDays($days), $today),
            '<t-' => new DateBound(null, $today->subDays($days)->subDay()->toDateString()),
            '><t-' => self::between($today->subDays($days), $today->subDay()),
            default => throw new QueryValidationException('Operator '.$operator.' is not a relative date.'),
        };
    }

    /**
     * Same window as {@see calendarBound()} converted into the application timezone.
     * An open side stays open. Datetime columns use the start of `from` and the end of `to`.
     */
    public function dateTimeBound(string $operator, int $days = 0): DateBound
    {
        $calendar = $this->calendarBound($operator, $days);
        if ($calendar->empty) {
            return $calendar;
        }

        $from = null;
        $to = null;
        if ($calendar->from !== null && $calendar->to !== null) {
            [$from, $to] = $this->explicitDatetimes($calendar->from, $calendar->to);
        } elseif ($calendar->from !== null) {
            [$from] = $this->explicitDatetimes($calendar->from, $calendar->from);
        } elseif ($calendar->to !== null) {
            [, $to] = $this->explicitDatetimes($calendar->to, $calendar->to);
        }

        return new DateBound($from, $to, $from === null && $to === null);
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function explicitDatetimes(string $fromDate, string $toDate): array
    {
        $zone = $this->today->getTimezone();
        $app = self::applicationZone();
        $start = CarbonImmutable::parse($fromDate.' 00:00:00', $zone)->timezone($app);
        $end = CarbonImmutable::parse($toDate.' 23:59:59', $zone)->timezone($app);

        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function previousWeek(CarbonImmutable $today): array
    {
        $anchor = $today->subWeek();

        return self::span(
            $anchor->startOfWeek(CarbonImmutable::MONDAY),
            $anchor->endOfWeek(CarbonImmutable::SUNDAY),
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function previousMonth(CarbonImmutable $today): array
    {
        $anchor = $today->subMonthNoOverflow();

        return self::span($anchor->startOfMonth(), $anchor->endOfMonth());
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function nextMonth(CarbonImmutable $today): array
    {
        $anchor = $today->addMonthNoOverflow();

        return self::span($anchor->startOfMonth(), $anchor->endOfMonth());
    }

    private static function closedDay(CarbonImmutable $day): DateBound
    {
        $date = $day->toDateString();

        return new DateBound($date, $date);
    }

    private static function closed(string $from, string $to): DateBound
    {
        return new DateBound($from, $to);
    }

    private static function between(CarbonImmutable $from, CarbonImmutable $to): DateBound
    {
        if ($from->greaterThan($to)) {
            return new DateBound(null, null, true);
        }

        return new DateBound($from->toDateString(), $to->toDateString());
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function span(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [$from->toDateString(), $to->toDateString()];
    }

    private static function zone(?User $user): DateTimeZone
    {
        if ($user !== null) {
            $stored = UserPreference::query()->where('user_id', $user->id)->value('time_zone');
            if (is_string($stored) && $stored !== '') {
                try {
                    return new DateTimeZone($stored);
                } catch (Exception) {
                    return self::applicationZone();
                }
            }
        }

        return self::applicationZone();
    }

    private static function applicationZone(): DateTimeZone
    {
        $configured = config('app.timezone');
        $name = is_string($configured) && $configured !== '' ? $configured : 'UTC';

        try {
            return new DateTimeZone($name);
        } catch (Exception) {
            return new DateTimeZone('UTC');
        }
    }
}
