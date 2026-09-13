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
        Schema::create('schedule_entries', function (Blueprint $table) {
            $table->id();

            // Externally addressable identifier (DEC-017).
            $table->ulid('public_id')->unique();

            // The creator is a real business-authority column, not mere
            // accountability metadata (contrast tasks.created_by_user_id/
            // work_logs.created_by_user_id, both nullOnDelete()) — the
            // creator retains standing edit/delete authority over their
            // own entry for its lifetime (docs/phases/
            // V1_PHASE_17_DEFINITION.md), so it is required and
            // restrictOnDelete: a Staff member who has created any
            // Schedule Entry cannot be deleted (see
            // StaffController::destroy) — the same philosophy as
            // tasks.assignee_staff_id/work_logs.staff_id.
            $table->foreignId('creator_staff_id')->constrained('staff')->restrictOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            // App\Enums\ScheduleEntryActivityType — the kind of activity
            // this generic Schedule Entry represents (meeting, client
            // visit, service appointment, company event, training,
            // internal activity, other). Never a separate table per
            // activity kind (docs/phases/V1_PHASE_17_DEFINITION.md).
            $table->string('activity_type');

            // Single pair of UTC datetime columns used for both timed and
            // all-day entries — the cleanest, minimally redundant
            // representation (no separate date-only column set). For a
            // timed entry these are the real start/end instants; for an
            // all-day entry they are the start-of-day/end-of-day instants
            // of the entry's date range in the configured company
            // timezone (config('scheduling.company_timezone'),
            // App\Support\CompanyTimezone), converted to UTC — a genuine,
            // meaningful boundary, never an arbitrary invented time (see
            // docs/phases/V1_PHASE_17_DEFINITION.md's Date/Time design).
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->boolean('is_all_day')->default(false);

            // Optional — a Schedule Entry may be personal/unlinked or
            // Project-linked (docs/phases/V1_PHASE_17_DEFINITION.md).
            // Department/Team-linked entries are explicitly out of scope
            // for Phase 17. restrictOnDelete: a Project with any Schedule
            // Entry still referencing it cannot be deleted (see
            // ProjectController::destroy) — mirrors Task's optional,
            // restrictOnDelete Project link (DEC-006/DEC-034).
            $table->foreignId('project_id')->nullable()->constrained('projects')->restrictOnDelete();

            $table->timestamps();

            $table->index(['starts_at', 'ends_at']);
            $table->index(['project_id', 'starts_at']);
            $table->index(['creator_staff_id', 'starts_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedule_entries');
    }
};
