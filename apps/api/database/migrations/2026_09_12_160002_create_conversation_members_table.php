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
        Schema::create('conversation_members', function (Blueprint $table) {
            $table->id();

            // Deliberately no public_id (DEC-017) — never independently
            // addressed by URL; membership rows are addressed by their
            // conversation's and member's Staff public_id within a
            // nested route (mirrors project_memberships' identical
            // reasoning).
            //
            // cascadeOnDelete: a membership row has no independent
            // meaning apart from its conversation (child data). In
            // practice never fires — there is no conversation
            // hard-delete endpoint in Phase 16.
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();

            // restrictOnDelete: a Staff member currently a member of any
            // conversation cannot be deleted (see StaffController::
            // destroy) — the same relational-integrity philosophy as
            // every other Staff-referencing table. Represents the
            // *current* roster only, mirroring project_memberships — no
            // historical-period tracking; leaving/being removed is a
            // hard delete of this row (message history is unaffected —
            // see messages.sender_staff_id).
            $table->foreignId('staff_id')->constrained('staff')->restrictOnDelete();

            // The minimum non-redundant read-position representation
            // (governing Phase 16 instructions): a reference to the
            // latest message this member has read, not a separate
            // timestamp — message ordering is already exact and gap-free
            // via the messages table's own auto-increment id, so a
            // timestamp alongside it would be a redundant second source
            // of the same fact. Null = nothing read yet. Unread count is
            // always derived (messages with id > this, or all messages
            // when null) — never a stored/cached count column.
            // nullOnDelete: inert in practice (messages are never
            // deleted in Phase 16); the safest declared behavior for a
            // soft, accountability-style reference.
            $table->foreignId('last_read_message_id')->nullable()->constrained('messages')->nullOnDelete();

            $table->timestamps();

            // A staff member has at most one (current) membership row
            // per conversation — mirrors project_memberships'
            // (project_id, staff_id) constraint exactly.
            $table->unique(['conversation_id', 'staff_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversation_members');
    }
};
