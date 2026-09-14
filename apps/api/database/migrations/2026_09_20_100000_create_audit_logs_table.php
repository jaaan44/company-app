<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The general, project-wide Audit Log (Phase 21, DEC-009/DEC-044) —
     * the backstop the roadmap named for it: "if [audit logging isn't
     * introduced early], Phase 21 (Integration Audit) is the backstop to
     * catch the gap" (docs/ROADMAP.md). Deliberately separate from every
     * module's own scoped `*_actions` history table (leave_request_actions,
     * service_report_actions, incident_report_actions) — those remain the
     * authoritative record for their own business workflow; this table
     * exists for administrative/security-sensitive events that span every
     * module, including several with no history table of their own at all
     * (Staff, Organization Structure, Clients, Projects, Announcements).
     *
     * `entity_type`/`entity_public_id` is a generic, typed reference pair
     * — not a Laravel-style polymorphic relationship and not an ownership
     * FK to every possible business table (there is no `entity_id`
     * foreign key of any kind here). It exists purely so an Administrator
     * can filter "everything that happened to this one record," the same
     * way `entity_public_id` values already address every other resource
     * in this API (DEC-017) — it carries no referential-integrity
     * guarantee and is never joined against.
     *
     * Append-only (no `updated_at` — see AuditLog model's `UPDATED_AT =
     * null`): there is no update/delete endpoint anywhere, not even for
     * Administrator (see AuditLogController).
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // The acting User — nullOnDelete so a historical entry is
            // never deleted merely because the acting account is later
            // removed (mirrors every *_actions.acted_by_user_id column).
            // Also legitimately null for a failed login where no User
            // could safely be resolved as the actor (see AuditLogger).
            $table->foreignId('actor_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            // Stable, dot-notation event name (e.g. "staff.updated") —
            // never derived from a PHP class name (see AuditActions).
            $table->string('action');

            // What kind of record this event is about (e.g. "Staff",
            // "User", "Department") — always populated. Not a foreign
            // key: see the class-level note above.
            $table->string('entity_type');

            // The specific record's own public_id (DEC-017), when one
            // applies — null for an event with no single resolvable
            // record (e.g. a failed login against an unknown email).
            $table->string('entity_public_id')->nullable();

            // Curated field names only (e.g. ["status","manager_id"]) —
            // never a full attribute dump. See AuditLogger::diff().
            $table->json('changed_fields')->nullable();

            // Curated, allowlisted structural values only (status, a
            // public-ID reference, etc.) — never a wholesale model
            // snapshot or raw request payload. Populated only from an
            // explicit per-caller allowlist; see each integration site.
            $table->json('before')->nullable();
            $table->json('after')->nullable();

            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();

            // "api" (Sanctum/mobile) or "admin" (session/Blade+Livewire)
            // — see App\Enums\AuditSource. This API has exactly two front
            // doors (05_SECURITY_MODEL.md §Authentication); no other
            // value is meaningful in V1.
            $table->string('source');

            $table->timestamp('created_at')->useCurrent();

            // Serves entity-scoped lookups ("everything that happened to
            // this Staff record").
            $table->index(['entity_type', 'entity_public_id']);
            // Serves actor-scoped lookups within a date range ("what did
            // this Administrator do, and when").
            $table->index(['actor_user_id', 'created_at']);
            // Serves action-scoped lookups within a date range ("every
            // staff.updated event last month").
            $table->index(['action', 'created_at']);
            // Deliberately no standalone `created_at` index: at this
            // company's scale (~100 employees, a curated V1 event list —
            // see docs/DECISIONS.md DEC-044), a bare date-range query
            // with no actor/action filter is not an anticipated access
            // pattern, and the table is small enough that the two
            // composite indexes above (or a full scan) already serve it
            // adequately. Add one later only if a real query plan shows
            // a need (CLAUDE.md §3 — no speculative infrastructure).
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
