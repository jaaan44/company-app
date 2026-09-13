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
        Schema::create('service_reports', function (Blueprint $table) {
            $table->id();

            // Externally addressable identifier (DEC-017).
            $table->ulid('public_id')->unique();

            // Client is the required business anchor (docs/phases/
            // V1_PHASE_18_DEFINITION.md's Core Concept) — a Service
            // Report always documents work performed for a Client; it is
            // never a generic internal activity record. restrictOnDelete:
            // a Client with any Service Report cannot be deleted (see
            // ClientController::destroy) — the same relational-integrity
            // philosophy as Project/Contact.
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();

            // Optional (mirrors tasks.project_id/work_logs.project_id —
            // DEC-006's "optional project" precedent extended here). When
            // set, must correspond to the same Client — enforced at the
            // application layer (ResolvesServiceReportReferences), not a
            // DB CHECK constraint, consistent with this codebase's
            // existing cross-field coherence rules (e.g. Work Log's
            // Task/Project single source of truth). restrictOnDelete: a
            // Project with any Service Report cannot be deleted (see
            // ProjectController::destroy).
            $table->foreignId('project_id')->nullable()->constrained('projects')->restrictOnDelete();

            // Optional. When set, its own Project (if any) must not
            // contradict the report's project_id/client_id — see the
            // same coherence note above. restrictOnDelete: a Task with
            // any Service Report cannot be deleted (see
            // TaskController::destroy).
            $table->foreignId('task_id')->nullable()->constrained('tasks')->restrictOnDelete();

            // The primary performer — required, never nullable (docs/
            // phases/V1_PHASE_18_DEFINITION.md). A real, standing
            // business-authority column (mirrors
            // schedule_entries.creator_staff_id, not the accountability-
            // only tasks.created_by_user_id pattern): the creator retains
            // draft edit/delete/submit authority over their own report.
            // restrictOnDelete: a Staff member who created any Service
            // Report cannot be deleted (see StaffController::destroy).
            $table->foreignId('creator_staff_id')->constrained('staff')->restrictOnDelete();

            // Accountability only — distinguishes a self-service report
            // from one an Administrator entered on the staff member's
            // behalf (mirrors tasks.created_by_user_id/
            // work_logs.created_by_user_id/leave_requests.created_by_user_id).
            // nullOnDelete: never deletes the historical record merely
            // because the creating User account later is.
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // DATE, not datetime — a Service Report documents which day
            // service occurred, never a specific time-of-day (docs/
            // phases/V1_PHASE_18_DEFINITION.md's Report Fields —
            // deliberately no labor-time/start-end tracking).
            $table->date('service_date');

            // Required narrative fields. `text` columns (mirroring
            // announcements.body) — length is bounded at the validation
            // layer (StoreServiceReportRequest/UpdateServiceReportRequest),
            // not the DB column, since a plain `string` column's length
            // limit is impractical at these sizes. work_performed is the
            // core narrative (largest bound, 10,000 chars);
            // findings/recommendations/follow_up_actions are each a
            // smaller, more focused note (5,000 chars each).
            $table->text('work_performed');
            $table->text('findings')->nullable();
            $table->text('recommendations')->nullable();
            $table->text('follow_up_actions')->nullable();

            // Plain text only — no digital signature field (docs/phases/
            // V1_PHASE_18_DEFINITION.md's Explicitly Out of Scope; no
            // public client portal exists for a customer to sign
            // anything through, per 00_PROJECT_CHARTER.md).
            $table->string('site_representative_name', 255)->nullable();

            // App\Enums\ServiceReportStatus — draft/submitted/reviewed/
            // rejected. No SoftDeletes — one lifecycle mechanism, matching
            // every prior module's precedent.
            $table->string('status')->default('draft');

            $table->timestamps();

            $table->index(['client_id', 'status']);
            $table->index(['project_id', 'status']);
            $table->index(['creator_staff_id', 'status']);
            $table->index(['service_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_reports');
    }
};
