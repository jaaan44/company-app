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
        Schema::create('announcement_acknowledgements', function (Blueprint $table) {
            $table->id();

            // Deliberately no public_id (DEC-017) — never independently
            // addressed by URL, only read as part of its parent
            // Announcement's own resource shape (mirrors
            // project_memberships/staff_statuses' identical reasoning).
            // cascadeOnDelete: in practice this never fires — only a
            // still-draft Announcement can be hard-deleted, and a draft
            // (never yet published) can have no acknowledgements.
            $table->foreignId('announcement_id')->constrained('announcements')->cascadeOnDelete();

            // restrictOnDelete: a Staff member with any acknowledgement
            // history cannot be deleted (see StaffController::destroy) —
            // the same relational-integrity philosophy as every other
            // Staff-referencing history table.
            $table->foreignId('staff_id')->constrained('staff')->restrictOnDelete();

            // created_at IS the acknowledgement timestamp — no separate
            // acknowledged_at column, extending DEC-032/DEC-036's
            // "derive/reuse, don't duplicate" precedent to this
            // single-fact case.
            $table->timestamps();

            // A staff member acknowledges a given announcement at most
            // once — POST .../acknowledge is idempotent, backed by this
            // constraint (firstOrCreate).
            $table->unique(['announcement_id', 'staff_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('announcement_acknowledgements');
    }
};
