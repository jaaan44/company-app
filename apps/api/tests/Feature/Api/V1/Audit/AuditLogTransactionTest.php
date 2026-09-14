<?php

namespace Tests\Feature\Api\V1\Audit;

use App\Enums\AnnouncementStatus;
use App\Enums\StaffStatus;
use App\Models\Announcement;
use App\Models\Department;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fail-closed transactional behavior (Phase 21, DEC-044 correction) — a
 * controlled, real DB-level failure (dropping the `audit_logs` table so
 * any insert into it throws a genuine `QueryException`) proves the
 * business mutation and its audit entry actually commit or roll back
 * together, inside one real `DB::transaction()`, rather than merely
 * asserting the source code contains the word `DB::transaction`.
 *
 * `AuditLogger` — like every service class in this codebase — is `final`
 * (Mockery cannot mock a final class without subclassing it), so a
 * controlled DB-level failure is used instead of a service mock, per the
 * governing instructions' explicit allowance for either mechanism.
 * `RefreshDatabase` wraps each test in its own outer transaction, so the
 * dropped table is transparently restored for the next test — no manual
 * teardown is needed.
 */
class AuditLogTransactionTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdministrator(): User
    {
        $user = User::factory()->administrator()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    private function forceAuditLogInsertToFail(): void
    {
        Schema::drop('audit_logs');
    }

    // --- Create -------------------------------------------------------

    public function test_staff_create_rolls_back_when_audit_insert_fails(): void
    {
        $this->actingAsAdministrator();
        $this->forceAuditLogInsertToFail();

        $this->postJson('/api/v1/staff', [
            'employee_number' => 'EMP-AUDIT-FAIL',
            'first_name' => 'Should',
            'last_name' => 'NotPersist',
        ])->assertStatus(500);

        $this->assertDatabaseMissing('staff', ['employee_number' => 'EMP-AUDIT-FAIL']);
    }

    // --- Update ---------------------------------------------------------

    public function test_staff_update_rolls_back_when_audit_insert_fails(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->create(['status' => StaffStatus::Active]);

        $this->forceAuditLogInsertToFail();

        $this->putJson("/api/v1/staff/{$staff->public_id}", ['status' => 'inactive'])
            ->assertStatus(500);

        $this->assertSame(StaffStatus::Active, $staff->fresh()->status);
    }

    // --- Delete ---------------------------------------------------------

    public function test_department_delete_rolls_back_when_audit_insert_fails(): void
    {
        $this->actingAsAdministrator();
        $department = Department::factory()->create();

        $this->forceAuditLogInsertToFail();

        $this->deleteJson("/api/v1/departments/{$department->public_id}")
            ->assertStatus(500);

        $this->assertDatabaseHas('departments', ['id' => $department->id]);
    }

    // --- Existing multi-step/attachment-style transaction path -----------

    public function test_announcement_publish_remains_fail_closed_when_audit_insert_fails(): void
    {
        $this->actingAsAdministrator();
        $announcement = Announcement::factory()->create();

        $this->forceAuditLogInsertToFail();

        $this->postJson("/api/v1/announcements/{$announcement->public_id}/publish")
            ->assertStatus(500);

        $announcement->refresh();
        $this->assertSame(AnnouncementStatus::Draft, $announcement->status);
        $this->assertNull($announcement->published_at);
        $this->assertNull($announcement->published_by_user_id);
    }

    // --- Export -----------------------------------------------------------

    public function test_report_export_does_not_return_successfully_when_audit_insert_fails(): void
    {
        $this->actingAsAdministrator();
        Staff::factory()->create();

        $this->forceAuditLogInsertToFail();

        $this->get('/api/v1/reports/staff/export')->assertStatus(500);
    }

    public function test_audit_log_export_does_not_return_successfully_when_audit_insert_fails(): void
    {
        $this->actingAsAdministrator();
        $this->forceAuditLogInsertToFail();

        $this->get('/api/v1/audit-logs/export')->assertStatus(500);
    }
}
