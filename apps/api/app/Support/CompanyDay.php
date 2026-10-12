<?php

namespace App\Support;

use App\Support\Reporting\OverdueTasks;
use Illuminate\Support\Carbon;

/**
 * The company day as the mobile app reads it (DEC-052): the date of
 * "today" in the company timezone, the timezone, and its UTC offset at the
 * start of that day — the same three fields `/me/home`, `/me/schedule`
 * and the Phase 30B history lists return. Phase 31 onwards use this one
 * helper.
 */
final class CompanyDay
{
    /**
     * @return array{date: string, timezone: string, utc_offset: string}
     */
    public static function meta(): array
    {
        $today = OverdueTasks::todayInCompanyTimezone();

        return [
            'date' => $today,
            'timezone' => CompanyTimezone::value(),
            'utc_offset' => Carbon::parse($today, CompanyTimezone::value())->startOfDay()->format('P'),
        ];
    }

    /** The company year, e.g. 2026. */
    public static function year(): int
    {
        return (int) substr(OverdueTasks::todayInCompanyTimezone(), 0, 4);
    }
}
