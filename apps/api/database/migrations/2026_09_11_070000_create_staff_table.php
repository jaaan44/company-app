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
        Schema::create('staff', function (Blueprint $table) {
            $table->id();

            // Externally addressable identifier (DEC-017) — Staff is
            // admin-manageable business data, never addressed by clients
            // via its internal numeric id.
            $table->ulid('public_id')->unique();

            // Company-assigned identifier (e.g. a badge/HR number).
            // Admin-supplied and validated unique, not system-generated
            // (DEC-030) — no auto-numbering scheme is assumed for V1.
            $table->string('employee_number')->unique();

            $table->string('first_name');
            $table->string('last_name');
            $table->string('preferred_name')->nullable();

            // Professional contact details, deliberately distinct from
            // users.email (the login identity) — never synced
            // automatically, may differ or be absent.
            $table->string('company_email')->nullable()->unique();
            $table->string('company_phone')->nullable();

            // App\Enums\StaffStatus — 'active'/'inactive'/'separated'.
            // Employment lifecycle only, not the future Phase 9
            // operational/current status.
            $table->string('status')->default('active');

            $table->date('hire_date')->nullable();
            $table->date('separation_date')->nullable();

            // Phase 6 Organization Structure references. Nullable — a
            // staff record may exist before org placement is finalized.
            // restrictOnDelete: a Department/Team/Position with staff
            // still assigned cannot be deleted (extends the Phase 6
            // protection already applied for Team/Position dependents —
            // see DepartmentController::destroy).
            $table->foreignId('department_id')->nullable()
                ->constrained('departments')->restrictOnDelete();
            $table->foreignId('team_id')->nullable()
                ->constrained('teams')->restrictOnDelete();
            $table->foreignId('position_id')->nullable()
                ->constrained('positions')->restrictOnDelete();

            // Self-referencing manager relationship. restrictOnDelete: a
            // manager with direct reports cannot be deleted until they're
            // reassigned — enforced primarily at the application layer
            // (StaffController::destroy, a clear 409) with this as a
            // defense-in-depth backstop, same pattern as the Organization
            // Structure FKs above.
            $table->foreignId('manager_id')->nullable()
                ->constrained('staff')->restrictOnDelete();

            // Optional, at most one Staff per User (unique) — a Staff
            // record may have no login account, and a User may have no
            // Staff record (e.g. the seeded local Administrator). Never a
            // duplicate authentication mechanism: no credentials or
            // account-state columns live here. nullOnDelete: if the User
            // is ever removed, the Staff record itself is untouched, just
            // unlinked.
            $table->foreignId('user_id')->nullable()->unique()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff');
    }
};
