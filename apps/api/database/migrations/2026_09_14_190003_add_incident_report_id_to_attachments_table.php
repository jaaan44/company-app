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
        // Phase 19 (Incident Reports, DEC-042) is the second authorized
        // consumer of the shared `attachments` infrastructure the
        // `attachments` migration (Phase 18, DEC-041) explicitly
        // anticipated: "Phase 19 adds its own nullable
        // `incident_report_id` column and its own AttachmentOwnerType
        // case via an additive migration, without redesigning this
        // table or Service Reports." This migration is exactly that —
        // no existing column, index, or Service Report attachment
        // behavior is altered.
        Schema::table('attachments', function (Blueprint $table): void {
            $table->foreignId('incident_report_id')->nullable()->after('service_report_id')
                ->constrained('incident_reports')->cascadeOnDelete();

            $table->index(['incident_report_id']);
        });

        // `service_report_id` was NOT NULL "since exactly one owner type
        // exists today" (the original migration's own words) — now that
        // a second owner type exists, every row owns exactly one of the
        // two real FKs (per `owner_type`), so service_report_id must
        // become nullable too (an Incident Report attachment row has
        // service_report_id = NULL). This widens a constraint (NOT NULL
        // -> NULL); it does not change the column's type, remove its
        // foreign key, or touch any existing Service Report attachment
        // row's actual value.
        Schema::table('attachments', function (Blueprint $table): void {
            $table->foreignId('service_report_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table): void {
            $table->dropForeign(['incident_report_id']);
            $table->dropIndex(['incident_report_id']);
            $table->dropColumn('incident_report_id');
        });

        Schema::table('attachments', function (Blueprint $table): void {
            $table->foreignId('service_report_id')->nullable(false)->change();
        });
    }
};
