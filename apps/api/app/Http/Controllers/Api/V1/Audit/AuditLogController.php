<?php

namespace App\Http\Controllers\Api\V1\Audit;

use App\Http\Controllers\Api\V1\Audit\Concerns\AuthorizesAuditLogAccess;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Audit\AuditActions;
use App\Support\Reporting\CsvExport;
use App\Support\Reporting\PublicIdResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The general Audit Log's own read surface (Phase 21, DEC-009/DEC-044) —
 * Administrator-only (AuthorizesAuditLogAccess), API-only (no Admin
 * Backoffice UI in V1, consistent with every module since Phase 6), and
 * strictly read-only: there is no create/update/delete route anywhere in
 * this controller or `routes/api/v1.php` — even Administrator cannot
 * edit or delete an entry (see AuditLog's `UPDATED_AT = null`).
 *
 * No free-text search/full-text indexing (CLAUDE.md's lean-V1 direction)
 * — only the structured filters below, mirroring every Phase 20 Report
 * resource's identical filter shape.
 */
class AuditLogController extends Controller
{
    use AuthorizesAuditLogAccess;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeAdministrator($request);

        return AuditLogResource::collection(
            $this->filteredQuery($request)->paginate($request->integer('per_page', 50))
        );
    }

    /**
     * Streams every currently-filtered entry as CSV — reuses Phase 20's
     * CsvExport (streamed, UTF-8/BOM, formula-injection-safe, stable
     * headers, no internal numeric ids). Deliberately excludes
     * `before`/`after` from the CSV shape entirely (never dumping raw
     * JSON into a cell) — `changed_fields` alone (a plain, comma-joined
     * list of field names) is exposed; the full curated detail remains
     * available via the JSON `index()` above. Exporting the Audit Log
     * itself is, itself, audited — with a distinct action name
     * (`audit_log.exported`) so it's never confused with a Phase 20
     * business report export.
     */
    public function export(Request $request): StreamedResponse
    {
        $this->authorizeAdministrator($request);

        $this->auditLogger->recordForRequest(
            $request,
            AuditActions::AUDIT_LOG_EXPORTED,
            entityType: 'AuditLog',
            entityPublicId: null,
        );

        $rows = $this->filteredQuery($request)->cursor()->map(fn (AuditLog $log) => [
            $log->created_at->toIso8601String(),
            $log->actor?->public_id,
            $log->action,
            $log->entity_type,
            $log->entity_public_id,
            $log->source->value,
            $log->ip_address,
            implode(', ', $log->changed_fields ?? []),
        ]);

        return CsvExport::stream('audit-logs.csv', [
            'Timestamp', 'Actor Public ID', 'Action', 'Entity Type', 'Entity Public ID', 'Source', 'IP Address', 'Changed Fields',
        ], $rows);
    }

    /**
     * @return Builder<AuditLog>
     */
    private function filteredQuery(Request $request): Builder
    {
        $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'actor' => ['sometimes', 'string'],
            'action' => ['sometimes', 'string'],
            'entity_type' => ['sometimes', 'string'],
            'entity_public_id' => ['sometimes', 'string'],
        ]);

        return AuditLog::query()
            ->with('actor')
            ->when($request->filled('from'), fn ($query) => $query->where('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($query) => $query->where('created_at', '<=', $request->date('to')))
            ->when(
                $request->filled('actor'),
                fn ($query) => $query->where('actor_user_id', PublicIdResolver::resolve(User::class, $request->string('actor')->toString()) ?? -1)
            )
            ->when($request->filled('action'), fn ($query) => $query->where('action', $request->string('action')))
            ->when($request->filled('entity_type'), fn ($query) => $query->where('entity_type', $request->string('entity_type')))
            ->when(
                $request->filled('entity_public_id'),
                fn ($query) => $query->where('entity_public_id', $request->string('entity_public_id'))
            )
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }
}
