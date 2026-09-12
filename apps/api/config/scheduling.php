<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Company Timezone
    |--------------------------------------------------------------------------
    |
    | Phase 17 (Scheduler) introduces the first real time-of-day scheduling
    | in this application. Every Schedule Entry timestamp is stored in UTC
    | (App\Models\ScheduleEntry casts starts_at/ends_at as UTC datetimes,
    | matching this API's existing ISO-8601-UTC convention). This single
    | value is the one place "what does a whole calendar day mean" is
    | resolved for an all-day Schedule Entry and for the date-only sources
    | (Task due dates, Leave dates, Project Milestone due dates) aggregated
    | into the unified Scheduler projection — see
    | App\Support\Scheduling\CompanyTimezone.
    |
    | V1 assumes a single company-wide timezone, not a per-User/per-Staff
    | one (docs/phases/V1_PHASE_17_DEFINITION.md) — there is no evidence
    | this ~100-employee company operates across multiple timezones, and
    | per-user timezone conversion is explicitly out of scope for Phase 17.
    |
    */

    'company_timezone' => env('SCHEDULING_COMPANY_TIMEZONE', 'UTC'),

];
