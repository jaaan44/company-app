<?php

namespace App\Support\Http;

use Illuminate\Http\Request;

/**
 * The page size a list endpoint uses (Phase 37, DEC-067 H-7): the
 * request's `per_page`, or the endpoint's default, kept between 1 and
 * 100. A larger request gets 100 rather than an error, so no client
 * breaks.
 */
final class PerPage
{
    public const MAX = 100;

    private function __construct() {}

    public static function from(Request $request, int $default = 50): int
    {
        return max(1, min(self::MAX, $request->integer('per_page', $default)));
    }
}
