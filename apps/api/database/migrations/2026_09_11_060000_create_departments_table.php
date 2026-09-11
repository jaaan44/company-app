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
        Schema::create('departments', function (Blueprint $table) {
            $table->id();

            // Externally addressable identifier (DEC-017) — Departments are
            // admin-manageable business data, never addressed by clients via
            // their internal numeric id.
            $table->ulid('public_id')->unique();

            $table->string('name')->unique();
            $table->text('description')->nullable();

            // App\Enums\OrganizationStatus — 'active'/'inactive'. Retiring a
            // department flips this rather than deleting the row, so any
            // future references to it (e.g. Staff, in a later phase) are
            // preserved rather than orphaned.
            $table->string('status')->default('active');

            // Admin-controlled display ordering within the department list.
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
