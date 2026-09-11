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
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();

            // Externally addressable identifier (DEC-017).
            $table->ulid('public_id')->unique();

            // Required — a Contact always belongs to exactly one Client
            // (docs/phases/V1_PHASE_08_DEFINITION.md, no client-less
            // contacts). restrictOnDelete: a Client with Contacts still
            // referencing it cannot be deleted (see ClientController::
            // destroy) — the same relational-integrity philosophy as
            // Department/Staff (Phases 6/7).
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();

            $table->string('first_name');
            $table->string('last_name');
            $table->string('job_title')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();

            // At most one primary contact per client — enforced at the
            // application layer (ContactController, a DB transaction that
            // clears any other primary contact for the same client before
            // setting this one), not a DB partial-unique-index (MySQL/
            // SQLite portability).
            $table->boolean('is_primary')->default(false);

            // App\Enums\ContactStatus — 'active'/'inactive'.
            $table->string('status')->default('active');

            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
