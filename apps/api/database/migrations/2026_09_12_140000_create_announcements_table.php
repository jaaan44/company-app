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
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();

            // Externally addressable identifier (DEC-017) — independently
            // addressed via /api/v1/announcements/{public_id} and
            // /api/v1/me/announcements/{public_id}.
            $table->ulid('public_id')->unique();

            $table->string('title', 200);

            // Plain text (docs/phases/V1_PHASE_14_DEFINITION.md — no rich
            // text editor, no HTML sanitization subsystem).
            $table->text('body');

            // App\Enums\AnnouncementStatus — draft/published/archived.
            // The single source of truth for lifecycle state; no separate
            // overlapping inactive/expired column.
            $table->string('status')->default('draft');

            // App\Enums\AnnouncementAudienceType — company_wide/scoped.
            // A scoped announcement's actual targets live in
            // announcement_departments/announcement_teams.
            $table->string('audience_type')->default('company_wide');

            // Server-controlled only — set when the explicit `publish`
            // action runs (see AnnouncementController::publish()), never
            // client-supplied, never re-set by a later edit. Null while
            // still draft.
            $table->timestamp('published_at')->nullable();

            // Accountability only, never an authorization primitive —
            // mirrors tasks.created_by_user_id/work_logs.created_by_user_id.
            // Distinct from published_by_user_id: the Administrator who
            // drafted an announcement may not be the one who actually
            // published it.
            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->foreignId('published_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The shape both the management list and the employee feed
            // query against (status = published, ordered by published_at).
            $table->index(['status', 'published_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
