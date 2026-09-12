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
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();

            // Externally addressable identifier (DEC-017) — Task is
            // admin/business-manageable data, never addressed by clients
            // via its internal numeric id.
            $table->ulid('public_id')->unique();

            // Nullable (DEC-006 — Tasks may exist independently of a
            // Project; "internal company tasks must be possible without
            // creating artificial projects"). restrictOnDelete: a Project
            // with any Task still referencing it cannot be deleted (see
            // ProjectController::destroy) — same relational-integrity
            // philosophy as project_memberships (Phase 10), not a cascade.
            $table->foreignId('project_id')->nullable()
                ->constrained('projects')->restrictOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            // App\Enums\TaskStatus — 'todo'/'in_progress'/'blocked'/
            // 'completed'/'cancelled'. No SoftDeletes, no separate
            // archival flag — one lifecycle mechanism, matching the
            // Project/Department precedent.
            $table->string('status')->default('todo');

            // App\Enums\TaskPriority — 'low'/'normal'/'high'/'urgent'.
            $table->string('priority')->default('normal');

            // Single nullable assignee (docs/phases/V1_PHASE_11_DEFINITION.md
            // — no task_assignees pivot without a demonstrated V1
            // requirement). restrictOnDelete: a Staff member with any Task
            // still referencing them as assignee cannot be deleted (see
            // StaffController::destroy) — Tasks are business history, so
            // this is never a cascade. Eligibility (active Staff, and a
            // member of the Task's Project when one is set) is enforced
            // in the Form Requests only for *new* assignment, mirroring
            // Project Membership's own eligibility pattern (Phase 10) —
            // removing a Project Membership later does not touch this
            // column, preserving assignment history.
            $table->foreignId('assignee_staff_id')->nullable()
                ->constrained('staff')->restrictOnDelete();

            // Accountability only, not an authorization primitive beyond
            // Task creation itself. nullOnDelete: a Task's historical
            // record is never deleted just because the creating user
            // account later is — mirrors staff_statuses.changed_by_user_id
            // (Phase 9).
            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->date('due_date')->nullable();

            // Server-controlled only (never client-supplied — see
            // StoreTaskRequest/UpdateTaskRequest's 'prohibited' rule):
            // set to now() whenever status transitions to 'completed',
            // cleared when a completed Task is reopened (status moves
            // away from 'completed') — see docs/phases/
            // V1_PHASE_11_DEFINITION.md for why this differs from
            // Project.completed_date (a user-supplied historical date
            // that is deliberately preserved through reopening).
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['project_id', 'status']);
            $table->index(['assignee_staff_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
