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
        Schema::create('roles', function (Blueprint $table) {
            $table->id();

            // Canonical, code-referenced identifier (e.g. 'administrator') —
            // App\Models\Role's constants are the single source of truth for
            // valid values. A small, fixed V1 catalog (Administrator,
            // Manager, Staff) — not user-creatable in this phase.
            $table->string('name')->unique();

            // Human-readable display name (e.g. 'Administrator').
            $table->string('label');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
