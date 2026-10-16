<?php

namespace Tests\Feature\Api\V1;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Staff;
use App\Models\User;
use App\Support\Database\Like;
use App\Support\Http\PerPage;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase 37 (DEC-067 H-7): list sizes are capped, searches take `%`, `_`
 * and `!` literally, and the Audit log's dates are company days.
 */
class ListHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Sanctum::actingAs(User::factory()->administrator()->create(['name' => 'Ada Admin', 'email' => 'ada@company.test']));
    }

    public function test_a_page_never_holds_more_than_100_and_never_fewer_than_1(): void
    {
        $this->assertSame(100, PerPage::MAX);
        Staff::factory()->count(102)->create();

        $this->getJson('/api/v1/staff?per_page=1000')->assertOk()->assertJsonCount(100, 'data')->assertJsonPath('meta.per_page', 100);
        $this->getJson('/api/v1/staff?per_page=0')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/staff?per_page=-5')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/staff')->assertOk()->assertJsonCount(50, 'data');
        $this->getJson('/api/v1/audit-logs?per_page=500')->assertOk()->assertJsonPath('meta.per_page', 100);
    }

    public function test_search_wildcards_are_taken_literally(): void
    {
        $this->assertSame('5!%!_!!', Like::escape('5%_!'));

        Staff::factory()->create(['first_name' => 'Fifty%', 'last_name' => 'Off', 'employee_number' => 'A_1']);
        Staff::factory()->create(['first_name' => 'Fifty5', 'last_name' => 'Off', 'employee_number' => 'AB1']);
        Staff::factory()->create(['first_name' => 'Wow!', 'last_name' => 'Nope', 'employee_number' => 'Z9']);

        $this->getJson('/api/v1/staff?q='.rawurlencode('fifty%'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.employee_number', 'A_1');
        $this->getJson('/api/v1/staff?q=A_1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.employee_number', 'A_1');
        $this->getJson('/api/v1/staff?q='.rawurlencode('w!'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.employee_number', 'Z9');
        $this->getJson('/api/v1/staff?q=%25')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/staff?q=fifty')->assertOk()->assertJsonCount(2, 'data');

        User::factory()->staff()->create(['email' => 'a_b@company.test']);
        User::factory()->staff()->create(['email' => 'axb@company.test']);
        $this->getJson('/api/v1/users?q=a_b')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.email', 'a_b@company.test');

        Client::factory()->create(['name' => '100% Fresh']);
        Client::factory()->create(['name' => '1000 Fresh']);
        $this->getJson('/api/v1/clients?q='.rawurlencode('100%'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', '100% Fresh');
    }

    public function test_the_audit_log_dates_are_company_days(): void
    {
        config(['scheduling.company_timezone' => 'Asia/Manila']);
        // 23:30 on the 15th and 00:30 on the 16th in Manila; 23:30 on the 16th.
        $late15 = AuditLog::factory()->create(['created_at' => Carbon::parse('2026-10-15 15:30:00', 'UTC')]);
        $early16 = AuditLog::factory()->create(['created_at' => Carbon::parse('2026-10-15 16:30:00', 'UTC')]);
        $late16 = AuditLog::factory()->create(['created_at' => Carbon::parse('2026-10-16 15:30:00', 'UTC')]);
        $early17 = AuditLog::factory()->create(['created_at' => Carbon::parse('2026-10-16 16:30:00', 'UTC')]);

        $ids = fn (string $query) => collect($this->getJson('/api/v1/audit-logs?'.$query)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$early16->public_id, $late16->public_id], $ids('from=2026-10-16&to=2026-10-16'));
        $this->assertSame([$late15->public_id], $ids('to=2026-10-15'));
        $this->assertSame([$early17->public_id], $ids('from=2026-10-17'));
    }
}
