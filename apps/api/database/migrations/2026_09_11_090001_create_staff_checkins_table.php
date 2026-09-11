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
        Schema::create('staff_checkins', function (Blueprint $table) {
            $table->id();

            // Externally addressable (DEC-017) — an Administrator may need
            // to reference a single check-in directly, e.g.
            // DELETE /api/v1/check-ins/{public_id}.
            $table->ulid('public_id')->unique();

            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();

            // Required together — a check-in fundamentally records where
            // the staff member currently is. decimal(10,7) gives ~1cm
            // precision, well beyond what's actually needed but a
            // conventional, simple choice; no altitude/heading/velocity.
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);

            // Optional device-reported GPS accuracy, in meters. No
            // anti-spoofing validation is attempted (out of scope).
            $table->unsignedInteger('accuracy_meters')->nullable();

            // Optional free-text label (e.g. "Office"/"Client Site"/
            // "Remote"/"Warehouse"/"Field") — deliberately not a foreign
            // key into a Location Master/catalog module, which doesn't
            // exist and isn't warranted for V1.
            $table->string('location_label')->nullable();

            // Optional operational note. Bounded length; an operational
            // annotation only — never a work log, task update, or
            // timesheet entry.
            $table->string('note', 500)->nullable();

            // Optional App\Enums\OperationalStatus snapshot captured at
            // check-in time. Informational only — recording it here does
            // NOT also write a staff_statuses row; changing the staff
            // member's ongoing operational status is a separate, deliberate
            // POST /api/v1/me/status action.
            $table->string('status')->nullable();

            $table->timestamps();

            // "Current location" is derived as the latest row per staff
            // member (Staff::latestCheckIn(), latestOfMany()) — this index
            // supports that lookup and ordinary history pagination without
            // a denormalized cache column or a specialized time-series
            // store (unnecessary at ~100-employee, explicit-check-in scale).
            $table->index(['staff_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff_checkins');
    }
};
