<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Incident Reports (Phase 19, DEC-042) — the roadmap/PRD's own
        // "Incident Reports" line, resolved (per the preceding planning
        // audit and the product-owner decisions that superseded it) as
        // one generic entity covering workplace/safety, client-site,
        // operational, property/equipment, IT/security, and other
        // incidents (App\Enums\IncidentReportType) — never dedicated
        // tables per category. Unlike Service Reports (Phase 18,
        // DEC-041), Client is NOT a required anchor here: an Incident
        // Report may be entirely internal, with no Client/Project/Task
        // at all — all three anchors are optional.
        Schema::create('incident_reports', function (Blueprint $table): void {
            $table->id();

            // Externally addressable identifier (DEC-017).
            $table->ulid('public_id')->unique();

            // All three optional (docs/phases/V1_PHASE_19_DEFINITION.md's
            // Anchors) — an Incident Report may be a purely internal
            // record. When supplied, relational coherence (Project must
            // not contradict Client; Task must not contradict
            // Client/Project) is enforced at the application layer
            // (App\Http\Requests\IncidentReports\Concerns\
            // ResolvesIncidentReportReferences), not a DB CHECK —
            // consistent with Service Reports' identical precedent.
            // restrictOnDelete: a Client/Project/Task with any Incident
            // Report referencing it cannot be deleted (see
            // ClientController/ProjectController/TaskController::destroy()).
            $table->foreignId('client_id')->nullable()->constrained('clients')->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->restrictOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('tasks')->restrictOnDelete();

            // The Staff member whose incident report this is — required,
            // never nullable, and immutable after creation at every
            // status (docs/phases/V1_PHASE_19_DEFINITION.md's Reporter).
            // restrictOnDelete: a Staff member who has reported any
            // Incident Report cannot be deleted (see
            // StaffController::destroy()).
            $table->foreignId('reporter_staff_id')->constrained('staff')->restrictOnDelete();

            // The single Staff member responsible for investigating/
            // resolving this incident — nullable (an incident may be
            // created and remain unassigned), never a multi-investigator
            // pivot (docs/phases/V1_PHASE_19_DEFINITION.md's Assignment).
            // Mutated only via the dedicated assign/reassign/
            // start-investigation action endpoints, never a generic
            // PATCH, so every change is captured in
            // incident_report_actions. restrictOnDelete: a Staff member
            // currently assigned to any Incident Report cannot be
            // deleted.
            $table->foreignId('assigned_to_staff_id')->nullable()->constrained('staff')->restrictOnDelete();

            // Accountability only — distinguishes a self-service report
            // from one an Administrator entered on the reporting staff
            // member's behalf (mirrors service_reports.created_by_user_id
            // exactly). nullOnDelete: never deletes the historical record
            // merely because the creating User account later is.
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // A genuine datetime, not a date — an incident has a real
            // moment of occurrence (docs/phases/V1_PHASE_19_DEFINITION.md's
            // Occurrence Date/Time), unlike service_reports.service_date
            // (deliberately date-only). Stored in UTC; ISO-8601 UTC is
            // returned through the API. Company-timezone interpretation
            // (when needed for display) reuses App\Support\CompanyTimezone
            // (Phase 17) — no second timezone system was introduced.
            $table->dateTime('occurred_at');

            // Free-text only (docs/phases/V1_PHASE_19_DEFINITION.md's
            // Location) — no locations master-data table, no GPS/
            // latitude/longitude, mirroring staff_checkins.location_label's
            // identical "free text, no catalog table" precedent (Phase 9).
            $table->string('location', 255)->nullable();

            // App\Enums\IncidentReportType — a closed taxonomy on one
            // generic entity, never dedicated tables per category.
            $table->string('incident_type');

            // App\Enums\IncidentSeverity — defaults to 'medium' (see
            // App\Models\IncidentReport::booted()). No automatic
            // severity/risk scoring.
            $table->string('severity')->default('medium');

            // Narrative fields (docs/phases/V1_PHASE_19_DEFINITION.md's
            // Narrative Fields) — bounded at the validation layer, not
            // the DB column, mirroring service_reports' identical
            // approach. Only `description` is required at creation;
            // investigation/resolution fields are deliberately nullable —
            // they are not required until the relevant workflow
            // transition (see Store/Update/ResolveIncidentReportRequest).
            $table->text('description');
            $table->text('immediate_action_taken')->nullable();
            $table->text('root_cause')->nullable();
            $table->text('corrective_action')->nullable();
            $table->text('preventive_action')->nullable();
            $table->text('follow_up_actions')->nullable();
            $table->text('resolution')->nullable();

            // Narrative-only stand-ins for external/customer persons and
            // witnesses (docs/phases/V1_PHASE_19_DEFINITION.md's External
            // Persons/Witnesses) — deliberately not structured tables and
            // never linked to Users/Contacts; Company App has no
            // external-person login concept (00_PROJECT_CHARTER.md rules
            // out a public-facing client portal).
            $table->text('people_involved')->nullable();
            $table->text('witness_notes')->nullable();

            // App\Enums\IncidentReportStatus — reported/under_investigation/
            // resolved/closed. Deliberately not Service Reports' draft/
            // submitted/reviewed/rejected shape (DEC-042) — an incident
            // must exist and be visible immediately upon reporting.
            $table->string('status')->default('reported');

            $table->timestamps();

            $table->index(['client_id', 'status']);
            $table->index(['project_id', 'status']);
            $table->index(['task_id']);
            $table->index(['reporter_staff_id', 'status']);
            $table->index(['assigned_to_staff_id', 'status']);
            $table->index(['occurred_at']);
            $table->index(['severity']);
            $table->index(['incident_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incident_reports');
    }
};
