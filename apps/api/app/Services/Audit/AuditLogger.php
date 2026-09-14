<?php

namespace App\Services\Audit;

use App\Enums\AuditSource;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The single, explicit entry point for writing to the general Audit Log
 * (Phase 21, DEC-009/DEC-044). Deliberately not a generic model observer,
 * mutation-middleware, or event bus — every call site names its own
 * stable action (App\Support\Audit\AuditActions) and curates its own
 * `changed_fields`/`before`/`after` from an explicit allowlist, so this
 * class never becomes a place sensitive data can leak into by accident
 * (CLAUDE.md §5 / DEC-044's redaction discipline).
 *
 * Each event path in the application calls this from exactly one place
 * (a controller or a narrowly-scoped model observer, never both — see
 * DEC-044) so the same business action can never be double-logged.
 */
final class AuditLogger
{
    /**
     * @param  array<int, string>  $changedFields  Field *names* only.
     * @param  array<string, mixed>  $before  Curated, allowlisted values only.
     * @param  array<string, mixed>  $after  Curated, allowlisted values only.
     */
    public function record(
        string $action,
        ?User $actor,
        string $entityType,
        ?string $entityPublicId,
        AuditSource $source,
        array $changedFields = [],
        array $before = [],
        array $after = [],
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): AuditLog {
        return AuditLog::create([
            'actor_user_id' => $actor?->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_public_id' => $entityPublicId,
            'changed_fields' => $changedFields === [] ? null : $changedFields,
            'before' => $before === [] ? null : $before,
            'after' => $after === [] ? null : $after,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'source' => $source,
        ]);
    }

    /**
     * Convenience for the overwhelmingly common case: an authenticated
     * API request, where the actor/IP/user agent all come straight from
     * the request itself. `source` defaults to `Api` — pass `Admin`
     * explicitly for a Blade/Livewire Admin Backoffice call site (there
     * is no request-derived way to distinguish the two guards reliably
     * enough to infer it here).
     *
     * @param  array<int, string>  $changedFields
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function recordForRequest(
        Request $request,
        string $action,
        string $entityType,
        ?string $entityPublicId,
        array $changedFields = [],
        array $before = [],
        array $after = [],
        AuditSource $source = AuditSource::Api,
        ?User $actor = null,
    ): AuditLog {
        return $this->record(
            action: $action,
            actor: $actor ?? $request->user(),
            entityType: $entityType,
            entityPublicId: $entityPublicId,
            source: $source,
            changedFields: $changedFields,
            before: $before,
            after: $after,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );
    }

    /**
     * Diffs two curated attribute arrays across an explicit allowlist of
     * field names only — never a full model attribute set. Returns the
     * subset of `$fields` whose value actually changed, plus the
     * matching curated before/after values for exactly those fields.
     * Used by every "significant update" integration site (Staff,
     * Department/Team/Position, Client/Contact, Project) so each one
     * doesn't hand-roll the same comparison — the allowlist itself is
     * still decided by the caller, never guessed here.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<int, string>  $fields
     * @return array{0: array<int, string>, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    public static function diff(array $before, array $after, array $fields): array
    {
        $changedFields = [];
        $curatedBefore = [];
        $curatedAfter = [];

        foreach ($fields as $field) {
            $beforeValue = $before[$field] ?? null;
            $afterValue = $after[$field] ?? null;

            if ($beforeValue !== $afterValue) {
                $changedFields[] = $field;
                $curatedBefore[$field] = $beforeValue;
                $curatedAfter[$field] = $afterValue;
            }
        }

        return [$changedFields, $curatedBefore, $curatedAfter];
    }
}
