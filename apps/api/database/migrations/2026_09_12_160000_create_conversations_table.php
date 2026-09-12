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
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();

            // Externally addressable identifier (DEC-017) — independently
            // addressed via /api/v1/conversations/{public_id}.
            $table->ulid('public_id')->unique();

            // App\Enums\ConversationType — direct/group/project. Never
            // client-supplied: each type is created only through its own
            // dedicated controller action (storeDirect/storeGroup/the
            // lazy project-conversation endpoint), so an invalid
            // type/name/project_id/owner_staff_id combination is
            // structurally unreachable rather than merely validated.
            $table->string('type');

            // Group conversations only; null for direct/project.
            $table->string('name')->nullable();

            // restrictOnDelete: a Project with a conversation still
            // referencing it cannot be deleted (see ProjectController::
            // destroy) — preserves historical Messaging data. Unique:
            // exactly one conversation per Project (Phase 16 — lazily
            // created on first use, never one-per-Project eagerly).
            $table->foreignId('project_id')->nullable()->unique()->constrained('projects')->restrictOnDelete();

            // Group conversations only; null for direct/project. The
            // single distinguished owner (Phase 16 — no co-owners, no
            // moderator roles). restrictOnDelete: a Staff member who
            // owns a group conversation cannot be deleted, even after
            // the group has been abandoned/emptied (see
            // StaffController::destroy) — this preserves who created it.
            $table->foreignId('owner_staff_id')->nullable()->constrained('staff')->restrictOnDelete();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
