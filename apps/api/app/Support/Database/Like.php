<?php

namespace App\Support\Database;

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;

/**
 * A "contains" search that treats every character literally (Phase 37,
 * DEC-067 H-7): `%` and `_` typed into a search box are no longer
 * wildcards. Escaped with `!` and an explicit `ESCAPE '!'`, which MySQL
 * and SQLite read the same way (a backslash is not portable).
 */
final class Like
{
    private function __construct() {}

    public static function escape(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    /**
     * Adds `(col1 LIKE %value% OR col2 LIKE %value% …)` to the query.
     *
     * @template TBuilder of BuilderContract
     *
     * @param  TBuilder  $query
     * @param  list<string>  $columns  column names from code, never input
     * @return TBuilder
     */
    public static function anyContains(BuilderContract $query, array $columns, string $value): BuilderContract
    {
        $term = '%'.self::escape($value).'%';

        return $query->where(function (BuilderContract $inner) use ($columns, $term): void {
            foreach ($columns as $column) {
                $wrapped = $inner->getGrammar()->wrap($column);
                $inner->orWhereRaw("{$wrapped} like ? escape '!'", [$term]);
            }
        });
    }
}
