<?php

namespace Tests\Feature\Api\V1\Audit;

use App\Enums\AuditSource;
use App\Models\AuditLog;
use App\Models\Staff;
use App\Models\User;
use App\Support\Audit\AuditActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Audit Log's own read surface (Phase 21, DEC-009/DEC-044):
 * authorization, pagination, filtering, CSV export, and immutability.
 */
class AuditLogApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdministrator(): User
    {
        $user = User::factory()->administrator()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    // --- Authorization --------------------------------------------------

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/audit-logs')->assertUnauthorized();
        $this->get('/api/v1/audit-logs/export')->assertUnauthorized();
    }

    public function test_administrator_can_list_audit_logs(): void
    {
        $this->actingAsAdministrator();
        AuditLog::factory()->count(3)->create();

        $this->getJson('/api/v1/audit-logs')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_administrator_can_export_audit_logs(): void
    {
        $this->actingAsAdministrator();
        AuditLog::factory()->create();

        $this->get('/api/v1/audit-logs/export')->assertOk();
    }

    public function test_manager_cannot_list_or_export_audit_logs(): void
    {
        $user = User::factory()->manager()->create();
        Sanctum::actingAs($user);
        AuditLog::factory()->create();

        $this->getJson('/api/v1/audit-logs')->assertForbidden();
        $this->get('/api/v1/audit-logs/export')->assertForbidden();
    }

    public function test_staff_cannot_list_or_export_audit_logs(): void
    {
        $user = User::factory()->staff()->create();
        Staff::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);
        AuditLog::factory()->create();

        $this->getJson('/api/v1/audit-logs')->assertForbidden();
        $this->get('/api/v1/audit-logs/export')->assertForbidden();
    }

    public function test_a_user_with_no_role_is_forbidden(): void
    {
        $user = User::factory()->create(['role_id' => null]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/audit-logs')->assertForbidden();
    }

    public function test_suspended_administrator_cannot_access_audit_logs(): void
    {
        $user = User::factory()->administrator()->suspended()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/audit-logs')->assertForbidden();
    }

    // --- Immutability -----------------------------------------------------

    public function test_no_write_endpoints_exist_for_the_audit_log(): void
    {
        $this->actingAsAdministrator();
        $log = AuditLog::factory()->create();

        // The URI pattern itself exists (GET only) — a POST to it is a
        // real, registered path with the wrong verb (405), never a
        // silently-accepted write. No {id}-bound route exists at all, so
        // PUT/DELETE against one is a genuine 404 — there is no route
        // pattern to match, not merely a rejected method.
        $this->postJson('/api/v1/audit-logs', [])->assertStatus(405);
        $this->putJson("/api/v1/audit-logs/{$log->id}", [])->assertNotFound();
        $this->deleteJson("/api/v1/audit-logs/{$log->id}")->assertNotFound();
    }

    // --- Filtering & pagination --------------------------------------------

    public function test_response_is_paginated_and_never_exposes_internal_ids(): void
    {
        $this->actingAsAdministrator();
        AuditLog::factory()->count(3)->create();

        $response = $this->getJson('/api/v1/audit-logs')->assertOk();

        $response->assertJsonStructure(['data', 'links', 'meta']);
        $response->assertJsonMissingPath('data.0.id');
        $response->assertJsonMissingPath('data.0.actor_user_id');
    }

    public function test_filters_by_actor(): void
    {
        $this->actingAsAdministrator();
        $actor = User::factory()->create();
        AuditLog::factory()->create(['actor_user_id' => $actor->id]);
        AuditLog::factory()->create();

        $this->getJson("/api/v1/audit-logs?actor={$actor->public_id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['actor' => ['public_id' => $actor->public_id]]]]);
    }

    public function test_filters_by_action(): void
    {
        $this->actingAsAdministrator();
        AuditLog::factory()->create(['action' => AuditActions::STAFF_CREATED]);
        AuditLog::factory()->create(['action' => AuditActions::CLIENT_DELETED]);

        $this->getJson('/api/v1/audit-logs?action='.AuditActions::STAFF_CREATED)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJson(['data' => [['action' => AuditActions::STAFF_CREATED]]]);
    }

    public function test_filters_by_entity_type_and_entity_public_id(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->create();
        AuditLog::factory()->create(['entity_type' => 'Staff', 'entity_public_id' => $staff->public_id]);
        AuditLog::factory()->create(['entity_type' => 'Department', 'entity_public_id' => 'something-else']);

        $this->getJson('/api/v1/audit-logs?entity_type=Staff')
            ->assertOk()->assertJsonCount(1, 'data');

        $this->getJson("/api/v1/audit-logs?entity_type=Staff&entity_public_id={$staff->public_id}")
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_filters_by_date_range(): void
    {
        $this->actingAsAdministrator();
        AuditLog::factory()->create(['created_at' => now()->subDays(10)]);
        $recent = AuditLog::factory()->create(['created_at' => now()->subHour()]);

        $response = $this->getJson('/api/v1/audit-logs?from='.now()->subDay()->toDateString().'&to='.now()->toDateString())
            ->assertOk();

        $response->assertJsonCount(1, 'data');
        $this->assertSame($recent->action, $response->json('data.0.action'));
    }

    public function test_no_free_text_search_parameter_is_supported(): void
    {
        $this->actingAsAdministrator();
        AuditLog::factory()->create(['action' => AuditActions::STAFF_CREATED]);

        // An unsupported 'q' filter is simply ignored (not validated as a
        // recognized filter) — no full-text search exists in V1.
        $response = $this->getJson('/api/v1/audit-logs?q=anything')->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    // --- CSV export ---------------------------------------------------------

    public function test_csv_export_has_stable_headers_and_no_internal_ids(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->create();
        AuditLog::factory()->create([
            'entity_type' => 'Staff',
            'entity_public_id' => $staff->public_id,
            'action' => AuditActions::STAFF_CREATED,
        ]);

        $response = $this->get('/api/v1/audit-logs/export');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();
        $headerLine = strstr(ltrim($csv, "\xEF\xBB\xBF"), "\n", true);
        $this->assertSame(
            ['Timestamp', 'Actor Public ID', 'Action', 'Entity Type', 'Entity Public ID', 'Source', 'IP Address', 'Changed Fields'],
            str_getcsv($headerLine),
        );
        $this->assertStringContainsString($staff->public_id, $csv);

        // No column of any row is ever the internal numeric id itself
        // (a bare substring check like "1," is too weak — an IP address
        // or ULID can coincidentally contain that sequence).
        $lines = array_filter(explode("\n", trim(ltrim($csv, "\xEF\xBB\xBF"))));
        foreach ($lines as $line) {
            $this->assertNotContains((string) $staff->id, str_getcsv($line));
        }
    }

    public function test_csv_export_applies_the_same_authorization_as_the_json_endpoint(): void
    {
        $user = User::factory()->create(['role_id' => null]);
        Sanctum::actingAs($user);

        $this->get('/api/v1/audit-logs/export')->assertForbidden();
    }

    public function test_csv_export_neutralizes_formula_injection(): void
    {
        $this->actingAsAdministrator();
        AuditLog::factory()->create([
            'entity_type' => '=cmd|"/c calc"',
            'action' => AuditActions::STAFF_CREATED,
        ]);

        $csv = $this->get('/api/v1/audit-logs/export')->streamedContent();

        $this->assertStringNotContainsString(',=cmd', $csv);
        $this->assertStringContainsString("'=cmd", $csv);
    }

    public function test_csv_export_does_not_dump_raw_before_after_json(): void
    {
        $this->actingAsAdministrator();
        AuditLog::factory()->create([
            'changed_fields' => ['status', 'manager_id'],
            'before' => ['status' => 'active'],
            'after' => ['status' => 'inactive'],
        ]);

        $csv = $this->get('/api/v1/audit-logs/export')->streamedContent();

        $this->assertStringNotContainsString('{"status"', $csv);
        $this->assertStringContainsString('status, manager_id', $csv);
    }

    public function test_exporting_the_audit_log_itself_creates_a_distinct_audit_event(): void
    {
        $admin = $this->actingAsAdministrator();

        $this->get('/api/v1/audit-logs/export')->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditActions::AUDIT_LOG_EXPORTED,
            'actor_user_id' => $admin->id,
            'source' => AuditSource::Api->value,
        ]);
    }
}
