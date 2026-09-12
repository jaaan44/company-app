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
        Schema::create('messages', function (Blueprint $table) {
            $table->id();

            // Externally addressable identifier (DEC-017) — each message
            // in a listed/paginated collection needs a stable client-
            // facing identifier, mirroring the Notification precedent
            // (Phase 15), even though there is no individual show/edit/
            // delete route for a single message.
            $table->ulid('public_id')->unique();

            // cascadeOnDelete: a message has no independent meaning apart
            // from its conversation (child data — mirrors
            // announcement_acknowledgements.announcement_id). In practice
            // this never fires: there is no conversation hard-delete
            // endpoint in Phase 16.
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();

            // restrictOnDelete: a Staff member who has sent any message
            // cannot be deleted (see StaffController::destroy) — message
            // history (including its author) is retained indefinitely,
            // per the governing Phase 16 instructions.
            $table->foreignId('sender_staff_id')->constrained('staff')->restrictOnDelete();

            // Plain text only, bounded (see StoreMessageRequest) — no
            // rich text/HTML sanitization subsystem, mirroring
            // Announcement's body precedent (DEC-037). 4,000 chars is a
            // deliberate choice: roomier than a single line, short of
            // Announcement's 10,000-char broadcast body, appropriate for
            // a chat message rather than a broadcast article.
            $table->string('body', 4000);

            // Messages are immutable after sending (Phase 16 — no
            // editing, no soft/hard deletion) — updated_at exists only
            // for Laravel's standard timestamps() convention and will
            // never differ from created_at in practice.
            $table->timestamps();

            // Ordered pagination within a conversation, newest-first —
            // the shape MessageController::index's query actually needs.
            $table->index(['conversation_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
