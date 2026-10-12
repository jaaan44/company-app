<?php

namespace App\Http\Requests\ServiceReports\Concerns;

use App\Support\Reporting\OverdueTasks;

/**
 * "No later than today" for a service report's `service_date` means the
 * company today (Phase 33, R-2a; DEC-052) — the Phase 29B work-date rule,
 * applied to service reports. Laravel's `before_or_equal:today` compared
 * against the UTC date, so between 00:00 and 07:59 Manila the company's
 * own today was refused.
 */
trait LimitsServiceDateToCompanyToday
{
    /**
     * The date rules for `service_date`, without `required`/`sometimes`.
     *
     * @return list<string>
     */
    private function serviceDateRules(): array
    {
        return ['date', 'before_or_equal:'.OverdueTasks::todayInCompanyTimezone()];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'service_date.before_or_equal' => 'The service date cannot be later than today.',
        ];
    }
}
