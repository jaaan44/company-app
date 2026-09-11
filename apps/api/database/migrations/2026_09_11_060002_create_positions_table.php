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
        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            // Nullable: positions may exist as standalone organizational
            // master data, independent of any department (per the
            // governing Phase 6 instructions). restrictOnDelete matches
            // teams' — see that migration's comment.
            $table->foreignId('department_id')->nullable()
                ->constrained('departments')->restrictOnDelete();

            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('active');
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            // Defense-in-depth for department-scoped uniqueness; see the
            // equivalent note on the teams migration re: NULL handling.
            $table->unique(['department_id', 'title']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('positions');
    }
};
