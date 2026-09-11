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
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            // Nullable: a team may exist before it's assigned to a
            // department (DEC-029). restrictOnDelete (not nullOnDelete /
            // cascadeOnDelete) — a department with teams still attached
            // cannot be deleted; see DepartmentController::destroy for the
            // primary, friendlier 409 enforcement this backstops.
            $table->foreignId('department_id')->nullable()
                ->constrained('departments')->restrictOnDelete();

            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('active');
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            // Defense-in-depth for the department-scoped uniqueness the
            // application layer enforces (see StoreTeamRequest /
            // UpdateTeamRequest). Note: both MySQL and SQLite treat NULL as
            // distinct in a unique index, so this index alone does not
            // catch duplicate names among teams with no department yet —
            // that scope is validated at the application layer.
            $table->unique(['department_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('teams');
    }
};
