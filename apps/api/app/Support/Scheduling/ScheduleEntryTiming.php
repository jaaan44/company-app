<?php

namespace App\Support\Scheduling;

use App\Support\CompanyTimezone;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The single place a Schedule Entry's `starts_at`/`ends_at` request
 * input is turned into the pair of UTC instants actually persisted
 * (Phase 17 — Scheduler). Used identically by StoreScheduleEntryRequest/
 * UpdateScheduleEntryRequest (to validate coherence and reject malformed
 * input with a 422) and ScheduleEntryController (to compute the final
 * values passed to ScheduleEntry::create()/update()) — one
 * implementation, never two potentially-diverging copies (mirroring this
 * codebase's established "shared Concern" precedent, e.g.
 * ChecksLeaveBalanceAvailability, DEC-036's Correction).
 *
 * A single pair of UTC datetime columns is used for both timed and
 * all-day entries (see the schedule_entries migration) — the cleanest,
 * minimally redundant storage shape. For a timed entry, the client
 * supplies a real ISO-8601 datetime for each. For an all-day entry, the
 * client supplies a plain calendar date (`Y-m-d`) for each, and this
 * class resolves it to the start/end of that day in the configured
 * company timezone (App\Support\CompanyTimezone) — a genuine, meaningful
 * boundary, never an arbitrary invented time.
 */
final class ScheduleEntryTiming
{
    /**
     * @return array{starts_at: Carbon, ends_at: Carbon}|null null when
     *                                                        either input cannot be parsed under the given $isAllDay format expectation.
     */
    public static function resolve(string $startsInput, string $endsInput, bool $isAllDay): ?array
    {
        try {
            if ($isAllDay) {
                if (! self::isDateOnly($startsInput) || ! self::isDateOnly($endsInput)) {
                    return null;
                }

                return [
                    'starts_at' => CompanyTimezone::startOfDayUtc($startsInput),
                    'ends_at' => CompanyTimezone::endOfDayUtc($endsInput),
                ];
            }

            return [
                'starts_at' => Carbon::parse($startsInput)->utc(),
                'ends_at' => Carbon::parse($endsInput)->utc(),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    private static function isDateOnly(string $value): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
    }
}
