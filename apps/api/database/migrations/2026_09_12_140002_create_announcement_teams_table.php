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
        // See announcement_departments — identical shape/reasoning,
        // targeting Teams instead. Union semantics across both tables.
        Schema::create('announcement_teams', function (Blueprint $table) {
            $table->foreignId('announcement_id')->constrained('announcements')->cascadeOnDelete();

            // restrictOnDelete: a Team still targeted by any Announcement's
            // audience cannot be deleted (see TeamController::destroy).
            $table->foreignId('team_id')->constrained('teams')->restrictOnDelete();

            $table->primary(['announcement_id', 'team_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('announcement_teams');
    }
};
