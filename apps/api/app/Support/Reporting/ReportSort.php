<?php

namespace App\Support\Reporting;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

/**
 * `?sort=` on a report (Phase 37B, DEC-068), the convention of
 * 04_API_CONVENTIONS.md: `?sort=due_date` ascending, `?sort=-due_date`
 * descending. Each report names the fields it allows (its own columns
 * only: names, dates and numbers). Without `sort`, the report keeps its
 * own default order; with one, the primary key breaks ties so pages
 * never overlap.
 */
final class ReportSort
{
    private function __construct() {}

    /**
     * @param  array<string, list<string>>  $fields  sort key => columns
     */
    public static function rule(array $fields): In
    {
        $keys = array_keys($fields);

        return Rule::in([...$keys, ...array_map(fn (string $key) => '-'.$key, $keys)]);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, list<string>>  $fields  sort key => columns
     * @param  array<string, 'asc'|'desc'>  $default  the report's own order: column => direction
     * @return Builder<TModel>
     */
    public static function apply(Request $request, Builder $query, array $fields, array $default): Builder
    {
        $sort = $request->string('sort')->toString();
        $key = ltrim($sort, '-');
        if ($sort === '' || ! array_key_exists($key, $fields)) {
            foreach ($default as $column => $direction) {
                $query->orderBy($column, $direction);
            }

            return $query;
        }

        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $model = $query->getModel();
        foreach ($fields[$key] as $column) {
            $query->orderBy($model->qualifyColumn($column), $direction);
        }

        return $query->orderBy($model->qualifyColumn($model->getKeyName()), $direction);
    }
}
