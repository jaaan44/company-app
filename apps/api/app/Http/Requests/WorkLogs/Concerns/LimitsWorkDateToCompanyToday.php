<?php

namespace App\Http\Requests\WorkLogs\Concerns;

use App\Support\Reporting\OverdueTasks;

/**
 * Shared by every Work Log Form Request (Phase 29B, R-8/R-10). A work log
 * can't be dated after **today in the company timezone** — the same
 * "today" as `/me/home`, `/me/tasks` and the Phase 20 reports
 * (OverdueTasks::todayInCompanyTimezone(), DEC-052/054).
 *
 * Phase 12's original `before_or_equal:today` compared against the UTC
 * date (`app.timezone` is UTC), so with an Asia/Manila company day the
 * Manila "today" was rejected as a future date from 00:00 to 07:59 local
 * time. See docs/phases/V1_PHASE_29_DEFINITION.md §6.1/§6.3.
 */
trait LimitsWorkDateToCompanyToday
{
    /**
     * The date rules for `work_date`, without `required`/`sometimes`
     * (each request keeps its own presence rules).
     *
     * @return list<string>
     */
    private function workDateRules(): array
    {
        return ['date', 'before_or_equal:'.OverdueTasks::todayInCompanyTimezone()];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'work_date.before_or_equal' => 'The work date cannot be later than today.',
        ];
    }
}
