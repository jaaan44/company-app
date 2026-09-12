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
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();

            // Externally addressable identifier (DEC-017) — independently
            // addressed via /api/v1/me/notifications/{public_id}.
            $table->ulid('public_id')->unique();

            // Recipient identity is the User account, not Staff (DEC-038)
            // — a Notification can only ever be consumed through an
            // authenticated login, and Staff may exist without one
            // (DEC-030). cascadeOnDelete mirrors staff_statuses/
            // staff_checkins' "own personal history" pattern — in practice
            // this never fires, since no User deletion endpoint exists
            // anywhere in this application.
            $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();

            // App\Enums\NotificationType — a bounded, closed set of
            // business events that actually produce notifications (Phase
            // 15 ships exactly one: announcement_published). Never a raw
            // PHP/Notification class name.
            $table->string('type');

            $table->string('title', 200);

            // Short, plain-text, generic copy only — never a sensitive
            // domain detail (e.g. a leave reason). See docs/phases/
            // V1_PHASE_15_DEFINITION.md's Security / Data Leakage section.
            $table->string('message', 500);

            // Typed source reference (Option A: docs/phases/
            // V1_PHASE_15_DEFINITION.md's Source / Context Reference) —
            // deliberately not a polymorphic Eloquent relation; both
            // columns are null together for a notification with no
            // linkable source. App\Enums\NotificationSourceType;
            // source_public_id is the source's own public_id, never an
            // internal numeric id.
            $table->string('source_type')->nullable();
            $table->string('source_public_id')->nullable();

            // Null = unread. Set once, server-side, on first mark-read;
            // never reset, never a repeated-read history (DEC-038).
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            // The shape the self-service list query filters/sorts by:
            // always scoped to the authenticated recipient, optionally
            // to unread only, always newest first.
            $table->index(['recipient_user_id', 'read_at']);
            $table->index(['recipient_user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
