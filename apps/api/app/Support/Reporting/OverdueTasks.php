<?php

namespace App\Support\Reporting;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Support\CompanyTimezone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The single canonical "overdue Task" definition for Phase 20 (Dashboard
 * and the Tasks Report's `?overdue=1` filter, DEC-043): a Task whose
 * `due_date` is strictly before "today" in the configured company
 * timezone (App\Support\CompanyTimezone, Phase 17) and whose `status` is
 * not Completed or Cancelled. Defined once here and reused everywhere
 * this codebase refers to an overdue Task — Phase 11's own handoff
 * explicitly left this concept unbuilt ("no due-date/overdue filter...
 * no demonstrated need at V1 scale"), so this is Phase 20's own,
 * deliberate definition, not a pre-existing one it is merely exposing.
 */
final class OverdueTasks
{
    public static function todayInCompanyTimezone(): string
    {
        return Carbon::now(CompanyTimezone::value())->toDateString();
    }

    /**
     * @param  Builder<Task>  $query
     * @return Builder<Task>
     */
    public static function scope(Builder $query): Builder
    {
        return $query
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', self::todayInCompanyTimezone())
            ->whereNotIn('status', [TaskStatus::Completed->value, TaskStatus::Cancelled->value]);
    }
}
