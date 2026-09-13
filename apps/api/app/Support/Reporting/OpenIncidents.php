<?php

namespace App\Support\Reporting;

use App\Enums\IncidentReportStatus;
use App\Models\IncidentReport;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single canonical "open Incident Report" definition for Phase 20
 * (Dashboard, DEC-043): `status IN (reported, under_investigation)` —
 * explicitly excluding `resolved`/`closed`. Defined once and reused
 * anywhere this codebase needs to say "an incident is still open."
 */
final class OpenIncidents
{
    /**
     * @param  Builder<IncidentReport>  $query
     * @return Builder<IncidentReport>
     */
    public static function scope(Builder $query): Builder
    {
        return $query->whereIn('status', [
            IncidentReportStatus::Reported->value,
            IncidentReportStatus::UnderInvestigation->value,
        ]);
    }
}
