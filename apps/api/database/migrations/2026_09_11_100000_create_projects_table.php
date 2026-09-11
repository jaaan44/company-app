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
        Schema::create('projects', function (Blueprint $table) {
            $table->id();

            // Externally addressable identifier (DEC-017) — Project is
            // admin-manageable business data, never addressed by clients
            // via its internal numeric id.
            $table->ulid('public_id')->unique();

            // Optional business reference (e.g. a job/reference number).
            // Nullable and admin-supplied, not system-generated — mirrors
            // clients.client_code (docs/phases/V1_PHASE_10_DEFINITION.md).
            $table->string('project_code')->nullable()->unique();

            $table->string('name');
            $table->text('description')->nullable();

            // Optional — a Project may be internal (client_id = null).
            // restrictOnDelete: a Client with Projects still referencing it
            // cannot be deleted (see ClientController::destroy) — the same
            // relational-integrity philosophy as Contact (Phase 8).
            $table->foreignId('client_id')->nullable()
                ->constrained('clients')->restrictOnDelete();

            // App\Enums\ProjectStatus — 'planned'/'active'/'on_hold'/
            // 'completed'/'cancelled'. No SoftDeletes — one lifecycle
            // mechanism, matching the Department/Client precedent.
            $table->string('status')->default('planned');

            $table->date('start_date')->nullable();
            $table->date('target_end_date')->nullable();
            $table->date('completed_date')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
