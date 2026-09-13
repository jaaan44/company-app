<?php

namespace App\Support\Reporting;

use App\Support\CompanyTimezone;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Resolves the Dashboard's period-based `from`/`to` window (Phase 20 —
 * Admin Dashboard & Reporting, DEC-043's date-range semantics): when the
 * caller supplies either `from` or `to`, both are resolved (the missing
 * one defaulting to the current company-timezone calendar month's own
 * boundary); when neither is supplied, the window defaults to the
 * current calendar month in the configured company timezone
 * (config('scheduling.company_timezone'), App\Support\CompanyTimezone,
 * Phase 17) — never the server process's own default timezone.
 *
 * This governs only *period-based* Dashboard metrics (Work Log hours,
 * Leave approved-in-period, Service Report/Incident Report period
 * breakdowns). Point-in-time metrics (active Staff/Clients/Projects,
 * current Projects/Tasks-by-status, overdue Tasks, open Incidents) never
 * consult this class at all — see docs/phases/V1_PHASE_20_DEFINITION.md.
 *
 * `fromDate`/`toDate` are plain `Y-m-d` company-timezone calendar dates,
 * for comparison against this codebase's existing DATE-typed columns
 * (work_date, service_date, leave_requests.start_date/end_date) exactly
 * the way every other module's own `?from=&to=` filter already does
 * (whereDate(...) against a plain date string — no timezone conversion
 * is meaningful for a column with no time component). `utcFrom()`/
 * `utcTo()` are true UTC instants (via CompanyTimezone's existing
 * startOfDayUtc()/endOfDayUtc()) for the one Phase 20 metric that
 * filters a genuine datetime column (Incident Reports' `occurred_at`).
 */
final class ReportPeriod
{
    public function __construct(
        public readonly string $fromDate,
        public readonly string $toDate,
        public readonly bool $wasExplicit,
    ) {}

    public static function resolve(Request $request): self
    {
        $companyNow = Carbon::now(CompanyTimezone::value());
        $explicit = $request->filled('from') || $request->filled('to');

        $fromDate = $request->filled('from')
            ? $request->date('from')->toDateString()
            : $companyNow->copy()->startOfMonth()->toDateString();

        $toDate = $request->filled('to')
            ? $request->date('to')->toDateString()
            : $companyNow->copy()->endOfMonth()->toDateString();

        return new self($fromDate, $toDate, $explicit);
    }

    public function utcFrom(): Carbon
    {
        return CompanyTimezone::startOfDayUtc($this->fromDate);
    }

    public function utcTo(): Carbon
    {
        return CompanyTimezone::endOfDayUtc($this->toDate);
    }
}
