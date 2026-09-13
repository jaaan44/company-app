<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * The single point of access for "what timezone does the company
 * operate in" (Phase 17 — Scheduler, `config('scheduling.company_timezone')`).
 * Nothing else in the codebase should reference that config key or a
 * hardcoded timezone string directly — this keeps the one genuinely
 * cross-cutting piece of scheduling business logic ("what does a whole
 * calendar day mean") in exactly one place, the same discipline this
 * codebase already applies to authorization (a single Gate::before) and
 * account-state enforcement (a single middleware).
 */
final class CompanyTimezone
{
    public static function value(): string
    {
        return config('scheduling.company_timezone', 'UTC');
    }

    /**
     * The UTC instant at the start of $date's calendar day in the
     * company timezone — used to normalize an all-day Schedule Entry's
     * `starts_at`, and to project a date-only source (a Task due date, a
     * Leave start date, a Milestone due date) into the unified Scheduler
     * representation without inventing a time-of-day for it.
     */
    public static function startOfDayUtc(string|Carbon $date): Carbon
    {
        return Carbon::parse(self::dateString($date), self::value())->startOfDay()->utc();
    }

    /**
     * The UTC instant at the end of $date's calendar day in the company
     * timezone — the `ends_at` counterpart to startOfDayUtc().
     */
    public static function endOfDayUtc(string|Carbon $date): Carbon
    {
        return Carbon::parse(self::dateString($date), self::value())->endOfDay()->utc();
    }

    /**
     * Reduces $date to a plain `Y-m-d` calendar-date string before
     * parsing it with the company timezone. A Carbon/DateTime input
     * (e.g. a date-cast Eloquent attribute such as `tasks.due_date`)
     * already carries its own timezone — passing it straight into
     * `Carbon::parse($date, $tz)` would have Carbon ignore $tz entirely
     * (it only applies to a plain string), silently defeating the
     * company-timezone normalization this class exists to centralize.
     */
    private static function dateString(string|Carbon $date): string
    {
        return $date instanceof Carbon ? $date->toDateString() : $date;
    }
}
