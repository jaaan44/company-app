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
        // A plain many-to-many pivot with no surrogate key, no role/
        // status hierarchy, no RSVP, no witness/injury-role enum
        // (docs/phases/V1_PHASE_19_DEFINITION.md's Participants) —
        // mirrors service_report_participants/schedule_entry_participants'
        // identical precedent exactly. A participant gains visibility
        // into the Incident Report but never management authority. The
        // reporter/assignee are deliberately never duplicated into this
        // table — their standing authority derives directly from
        // incident_reports.reporter_staff_id/assigned_to_staff_id.
        Schema::create('incident_report_participants', function (Blueprint $table): void {
            // Child data with no independent meaning apart from its
            // Incident Report — cascadeOnDelete.
            $table->foreignId('incident_report_id')->constrained('incident_reports')->cascadeOnDelete();

            // restrictOnDelete: a Staff member who is still a participant
            // on any Incident Report cannot be deleted (see
            // StaffController::destroy()) — mirrors
            // service_report_participants.staff_id.
            $table->foreignId('staff_id')->constrained('staff')->restrictOnDelete();

            $table->primary(['incident_report_id', 'staff_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incident_report_participants');
    }
};
