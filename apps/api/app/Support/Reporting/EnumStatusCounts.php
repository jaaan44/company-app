<?php

namespace App\Support\Reporting;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Server-side aggregated status/severity/etc. breakdowns for Dashboard
 * chart cards (Phase 20 — "Dashboard/chart endpoints return server-side
 * aggregated values/series," never raw records a client would have to
 * group itself). Every case of the given closed backed enum is present
 * in the result (0 when the caller's currently-visible set has none), so
 * a chart consumer never has to special-case a missing key.
 */
final class EnumStatusCounts
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<int, \BackedEnum>  $cases
     * @return array<string, int>
     */
    public static function forColumn(Builder $query, string $column, array $cases): array
    {
        $counts = (clone $query)
            ->select($column, DB::raw('count(*) as aggregate'))
            ->groupBy($column)
            ->pluck('aggregate', $column);

        $result = [];

        foreach ($cases as $case) {
            $value = $case->value;
            $result[$value] = (int) ($counts[$value] ?? 0);
        }

        return $result;
    }
}
