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
        Schema::create('work_logs', function (Blueprint $table) {
            $table->id();

            // Externally addressable identifier (DEC-017) — a Work Log is
            // independently addressed by URL (/api/v1/work-logs/{public_id},
            // /api/v1/me/work-logs/{public_id}), unlike project_memberships/
            // staff_statuses.
            $table->ulid('public_id')->unique();

            // The Staff member who performed the work — required, never
            // nullable/inferred beyond the two creation paths (self-service:
            // the authenticated User's own linked Staff record; Administrator
            // correction: an explicit staff_id). restrictOnDelete: a Staff
            // member with any Work Log cannot be deleted (see
            // StaffController::destroy) — historical work records are never
            // orphaned via cascade.
            $table->foreignId('staff_id')->constrained('staff')->restrictOnDelete();

            // Nullable — a Work Log may reference a Task, a Project, or
            // both consistently, but never neither (application-enforced:
            // at least one of task_id/project_id is required — see
            // StoreWorkLogRequest/StoreMyWorkLogRequest). restrictOnDelete:
            // a Task with any Work Log cannot be hard-deleted (see
            // TaskController::destroy).
            $table->foreignId('task_id')->nullable()
                ->constrained('tasks')->restrictOnDelete();

            // Nullable — when task_id is set, project_id is server-derived
            // from the Task's own project_id (never independently
            // client-supplied alongside a task_id — see
            // docs/phases/V1_PHASE_12_DEFINITION.md's "single source of
            // truth" rule) and may itself be null for an independent Task.
            // When task_id is null, project_id may be set directly (general
            // Project activity with no specific Task). restrictOnDelete: a
            // Project with any Work Log directly referencing it cannot be
            // deleted (see ProjectController::destroy).
            $table->foreignId('project_id')->nullable()
                ->constrained('projects')->restrictOnDelete();

            // Accountability only — who entered the record, never confused
            // with the performer (staff_id) and never an authorization
            // primitive beyond creation itself. nullOnDelete mirrors
            // tasks.created_by_user_id (Phase 11)/staff_statuses.
            // changed_by_user_id (Phase 9).
            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            // Duration model (docs/phases/V1_PHASE_12_DEFINITION.md) — no
            // start/end timestamps, no timer. work_date must not be in the
            // future (application-enforced) — a Work Log is inherently
            // retrospective.
            $table->date('work_date');

            // Integer minutes, not floating-point hours. Validated
            // positive with a sensible maximum (see StoreWorkLogRequest) —
            // this column itself only guarantees non-negativity at the DB
            // level.
            $table->unsignedInteger('duration_minutes');

            // A single required free-text field describing the work
            // performed — no separate note/summary fields, no threaded
            // comments.
            $table->string('description', 2000);

            $table->timestamps();

            $table->index(['staff_id', 'work_date']);
            $table->index(['project_id', 'work_date']);
            $table->index(['task_id', 'work_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_logs');
    }
};
