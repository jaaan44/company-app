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
        Schema::create('project_memberships', function (Blueprint $table) {
            $table->id();

            // restrictOnDelete: a Project with any membership still
            // referencing it cannot be deleted (see ProjectController::
            // destroy) — preserves the anchor future Tasks/Work Logs will
            // reference, rather than cascading (docs/phases/
            // V1_PHASE_10_DEFINITION.md).
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();

            // restrictOnDelete: a Staff member with any membership still
            // referencing them cannot be deleted (see StaffController::
            // destroy) — the same philosophy as Staff.manager_id
            // (Phase 7).
            $table->foreignId('staff_id')->constrained('staff')->restrictOnDelete();

            // App\Enums\ProjectMembershipRole — 'project_lead'/'member'.
            // Project-level responsibility only, never the global
            // Administrator/Manager/Staff application role.
            $table->string('role')->default('member');

            $table->timestamps();

            // A staff member has at most one (current) membership row per
            // project — no historical-period tracking; removing a
            // membership is a hard delete (see docs/phases/
            // V1_PHASE_10_DEFINITION.md).
            $table->unique(['project_id', 'staff_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_memberships');
    }
};
