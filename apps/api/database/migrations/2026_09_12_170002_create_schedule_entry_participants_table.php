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
        // A plain many-to-many pivot with no surrogate key (mirrors
        // role_permissions/announcement_departments/announcement_teams)
        // — participant simply means "this Schedule Entry appears on
        // that Staff member's schedule and they may view it," never an
        // RSVP/invited/accepted/declined/tentative state (docs/phases/
        // V1_PHASE_17_DEFINITION.md). The creator is deliberately never
        // duplicated into this table (avoids unnecessary duplicated
        // state) — creator visibility is derived from
        // schedule_entries.creator_staff_id directly.
        Schema::create('schedule_entry_participants', function (Blueprint $table) {
            // Child data with no independent meaning apart from its
            // Schedule Entry — cascadeOnDelete (contrast the
            // restrictOnDelete master-data relationships elsewhere in
            // this table's own parent).
            $table->foreignId('schedule_entry_id')->constrained('schedule_entries')->cascadeOnDelete();

            // restrictOnDelete: a Staff member who is still a participant
            // on any Schedule Entry cannot be deleted (see
            // StaffController::destroy) — mirrors
            // conversation_members.staff_id/project_memberships.staff_id.
            $table->foreignId('staff_id')->constrained('staff')->restrictOnDelete();

            $table->primary(['schedule_entry_id', 'staff_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedule_entry_participants');
    }
};
