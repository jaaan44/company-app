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
        Schema::create('project_milestones', function (Blueprint $table) {
            $table->id();

            // Externally addressable identifier (DEC-017) — a Milestone is
            // admin/business-manageable data, never addressed by clients
            // via its internal numeric id.
            $table->ulid('public_id')->unique();

            // Required (unlike Task's nullable project_id, DEC-006) — a
            // Milestone has no meaning apart from its Project (Phase 17 —
            // the minimal Milestone concept the Phase 17 roadmap
            // dependency incorrectly assumed Phase 10 had already built;
            // see docs/DECISIONS.md). restrictOnDelete: a Project with
            // any Milestone still referencing it cannot be deleted (see
            // ProjectController::destroy) — the same relational-integrity
            // philosophy as Task/Work Log/Project Membership, not a
            // cascade, since Milestones are business planning history.
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();

            $table->string('title');
            $table->date('due_date');

            // App\Enums\ProjectMilestoneStatus — 'pending'/'completed'/
            // 'cancelled'. The smallest useful lifecycle for a target-date
            // marker — no percent-complete, dependency graph, or
            // recurrence (docs/phases/V1_PHASE_17_DEFINITION.md).
            $table->string('status')->default('pending');

            $table->timestamps();

            $table->index(['project_id', 'due_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_milestones');
    }
};
