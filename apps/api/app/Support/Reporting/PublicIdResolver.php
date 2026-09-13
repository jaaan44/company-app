<?php

namespace App\Support\Reporting;

use Illuminate\Database\Eloquent\Model;

/**
 * Resolves a client-supplied public_id filter (?staff=, ?client=, ...)
 * into the internal numeric id every Phase 20 query actually filters by
 * — the identical small helper every existing controller already
 * privately duplicates as resolveId() (e.g. ProjectController,
 * WorkLogController, ServiceReportController, IncidentReportController).
 * Extracted once here because Dashboard/Reports (eight new controllers)
 * would otherwise duplicate the same three lines eight more times. No
 * internal numeric id is ever returned to a client — this only resolves
 * one server-side, for building a WHERE clause.
 */
final class PublicIdResolver
{
    /**
     * @param  class-string<Model>  $modelClass
     */
    public static function resolve(string $modelClass, ?string $publicId): ?int
    {
        if ($publicId === null || $publicId === '') {
            return null;
        }

        return $modelClass::query()->where('public_id', $publicId)->value('id');
    }
}
