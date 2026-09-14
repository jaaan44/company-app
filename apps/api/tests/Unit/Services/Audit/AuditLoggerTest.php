<?php

namespace Tests\Unit\Services\Audit;

use App\Enums\AuditSource;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Audit\AuditActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLoggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_diff_returns_only_fields_that_actually_changed(): void
    {
        $before = ['status' => 'active', 'manager_id' => 'mgr-1', 'unrelated' => 'x'];
        $after = ['status' => 'inactive', 'manager_id' => 'mgr-1', 'unrelated' => 'y'];

        [$changed, $curatedBefore, $curatedAfter] = AuditLogger::diff($before, $after, ['status', 'manager_id']);

        $this->assertSame(['status'], $changed);
        $this->assertSame(['status' => 'active'], $curatedBefore);
        $this->assertSame(['status' => 'inactive'], $curatedAfter);
    }

    public function test_diff_returns_nothing_when_no_allowlisted_field_changed(): void
    {
        [$changed, $before, $after] = AuditLogger::diff(
            ['status' => 'active', 'name' => 'Old Name'],
            ['status' => 'active', 'name' => 'New Name'],
            ['status'],
        );

        $this->assertSame([], $changed);
        $this->assertSame([], $before);
        $this->assertSame([], $after);
    }

    public function test_record_persists_a_new_append_only_entry(): void
    {
        $actor = User::factory()->create();

        $log = (new AuditLogger)->record(
            action: AuditActions::STAFF_CREATED,
            actor: $actor,
            entityType: 'Staff',
            entityPublicId: 'staff-public-id',
            source: AuditSource::Api,
            changedFields: ['status'],
            before: ['status' => 'active'],
            after: ['status' => 'inactive'],
            ipAddress: '127.0.0.1',
            userAgent: 'PHPUnit',
        );

        $this->assertDatabaseHas('audit_logs', [
            'id' => $log->id,
            'actor_user_id' => $actor->id,
            'action' => AuditActions::STAFF_CREATED,
            'entity_type' => 'Staff',
            'entity_public_id' => 'staff-public-id',
        ]);
    }

    public function test_actor_can_be_null_for_a_failed_login(): void
    {
        $log = (new AuditLogger)->record(
            action: AuditActions::AUTH_LOGIN_FAILED,
            actor: null,
            entityType: 'User',
            entityPublicId: null,
            source: AuditSource::Api,
        );

        $this->assertNull($log->actor_user_id);
    }

    public function test_actor_survives_when_the_user_is_later_deleted(): void
    {
        $actor = User::factory()->create();
        $log = (new AuditLogger)->record(
            action: AuditActions::STAFF_CREATED,
            actor: $actor,
            entityType: 'Staff',
            entityPublicId: 'x',
            source: AuditSource::Api,
        );

        $actor->delete();

        $log->refresh();
        $this->assertNull($log->actor_user_id);
        $this->assertDatabaseHas('audit_logs', ['id' => $log->id]);
    }

    public function test_the_audit_log_has_no_updated_at_column(): void
    {
        $log = AuditLog::factory()->create();

        $this->assertNull($log->getAttribute('updated_at'));
        $this->assertNull(AuditLog::UPDATED_AT);
    }

    public function test_empty_metadata_arrays_are_stored_as_null_not_empty_arrays(): void
    {
        $log = (new AuditLogger)->record(
            action: AuditActions::AUTH_LOGOUT,
            actor: User::factory()->create(),
            entityType: 'User',
            entityPublicId: 'x',
            source: AuditSource::Api,
        );

        $this->assertNull($log->changed_fields);
        $this->assertNull($log->before);
        $this->assertNull($log->after);
    }
}
