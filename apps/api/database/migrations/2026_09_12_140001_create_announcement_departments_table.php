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
        // Many-to-many announcement<->department join, scoping a 'scoped'
        // Announcement's audience (union semantics with
        // announcement_teams — see docs/phases/V1_PHASE_14_DEFINITION.md).
        // A plain pivot: no surrogate id/timestamps, mirroring
        // role_permissions' shape (DEC-017 — pivot tables generally don't
        // need one).
        Schema::create('announcement_departments', function (Blueprint $table) {
            // cascadeOnDelete: child data with no independent meaning
            // apart from its Announcement. In practice this only ever
            // fires for a still-draft Announcement (the only hard-deletable
            // state), and a draft can have no audience rows referenced by
            // anyone yet.
            $table->foreignId('announcement_id')->constrained('announcements')->cascadeOnDelete();

            // restrictOnDelete: a Department still targeted by any
            // Announcement's audience cannot be deleted (see
            // DepartmentController::destroy) — an Announcement's
            // historical audience is never silently orphaned by an
            // org-structure change.
            $table->foreignId('department_id')->constrained('departments')->restrictOnDelete();

            $table->primary(['announcement_id', 'department_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('announcement_departments');
    }
};
