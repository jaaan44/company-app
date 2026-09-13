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
        // The shared attachment/file infrastructure `03_DATABASE_MODEL.md`
        // §1 deferred at Phase 0 ("resolve when Service Reports and
        // shared infrastructure are actually built — don't build both"),
        // now built here (Phase 18, DEC-041) because Service Reports
        // requires it and Phase 19 (Incident Reports) is the concrete
        // next consumer.
        //
        // Deliberately NOT a Laravel-style pseudo-polymorphic pair
        // (`attachable_type` storing a raw PHP class name +
        // `attachable_id` with no real foreign key) — this codebase's
        // established preference is a typed, non-polymorphic reference
        // (DEC-017, NotificationSourceType, DEC-038) over generic
        // polymorphism, and a bare `attachable_id` column can never carry
        // a real, DB-enforced foreign key when it must point at more than
        // one possible parent table.
        //
        // Instead: `owner_type` (App\Enums\AttachmentOwnerType) is an
        // explicit, closed discriminator column, paired with a genuine,
        // real foreign key column *per owner type* — `service_report_id`
        // today, the only case that exists. This keeps full DB-level
        // referential integrity (a real FK, real cascadeOnDelete) while
        // remaining trivially extensible: Phase 19 adds its own nullable
        // `incident_report_id` column via an additive migration (and its
        // own AttachmentOwnerType case) without redesigning this table or
        // Service Reports — see docs/phases/V1_PHASE_18_DEFINITION.md's
        // Attachment Architecture and 03_DATABASE_MODEL.md's updated
        // Cross-Cutting section.
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();

            // Externally addressable identifier (DEC-017) — an
            // attachment is downloaded/removed via its own public_id,
            // scoped under its owning Service Report's URL.
            $table->ulid('public_id')->unique();

            // App\Enums\AttachmentOwnerType — currently always
            // 'service_report'. Documents which owner-FK column below is
            // populated; not itself load-bearing for referential
            // integrity (the real FK is what enforces that), but keeps
            // any future cross-owner-type listing/reporting code able to
            // discriminate without needing to know every FK column ever
            // added.
            $table->string('owner_type');

            // The one real owner FK that exists in V1. NOT NULL because
            // exactly one owner type exists today; cascadeOnDelete — an
            // attachment has no independent meaning apart from the
            // Service Report it documents, and only a still-draft report
            // (which therefore has no submitted/reviewed history to
            // protect) may ever be deleted, so this cascade only ever
            // fires for a draft's own attachments. Application code
            // deletes the underlying stored file BEFORE the database row
            // (see ServiceReportController::destroy() /
            // ServiceReportAttachmentController::destroy()) — file
            // storage is not part of the database transaction, so a
            // crash between the two steps could in principle leave a
            // dangling DB reference to an already-removed file, but never
            // an orphaned file with no DB reference (the documented,
            // accepted consistency boundary — see
            // docs/phases/V1_PHASE_18_DEFINITION.md).
            $table->foreignId('service_report_id')->constrained('service_reports')->cascadeOnDelete();

            // Preserved for display/download purposes ONLY — never
            // trusted as, or used to derive, the physical storage path
            // (see storage_path below and
            // App\Services\Attachments\AttachmentStorage).
            $table->string('original_filename', 255);

            // Which Laravel filesystem disk this file lives on (see
            // config/attachments.php, App\Support\Attachments\
            // AttachmentDisk) — stored per-row (not assumed to be
            // whatever the current config says) so a future disk
            // migration (e.g. local -> S3-compatible) can be rolled out
            // gradually without invalidating already-stored rows.
            $table->string('storage_disk', 50);

            // A generated, randomized key (ULID-based) — never derived
            // from original_filename, which could collide, contain path-
            // traversal characters, or leak into a predictable public
            // URL. Combined with storage_disk to physically locate the
            // file; never exposed through the API (AttachmentResource
            // exposes only public_id/original_filename/mime_type/
            // size_bytes).
            $table->string('storage_path', 500);

            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');

            // Accountability only. nullOnDelete mirrors every other
            // *_by_user_id column in this codebase.
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['service_report_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
