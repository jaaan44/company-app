<?php

namespace Tests\Feature\Portal;

use App\Enums\AuditSource;
use App\Models\AuditLog;
use App\Models\Staff;
use App\Models\Task;
use App\Models\User;
use App\Services\Portal\InternalApi;
use App\Services\Portal\PortalApiException;
use App\Support\Audit\AuditActions;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The Staff Web Portal's in-process API client (Phase 34A, DEC-063 W-1):
 * it acts as the web session user, the API's own middleware and rules
 * decide, no token is created, errors map as on mobile, the calling
 * request and route are restored, and writes are audited as `web`.
 */
class InternalApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        // A real web page that calls the API, as every portal screen does.
        Route::middleware(['web', 'auth'])->any('/__portal-probe', function (Request $outer) {
            $calls = [];
            foreach ((array) $outer->input('calls') as $call) {
                [$method, $path, $json] = [$call['method'], $call['path'], $call['json'] ?? []];
                try {
                    $body = app(InternalApi::class)->send($method, $path, json: $json);
                    $calls[] = ['ok' => true, 'body' => $body];
                } catch (PortalApiException $e) {
                    $calls[] = ['ok' => false, 'status' => $e->status, 'message' => $e->getMessage(), 'errors' => $e->errors];
                }
            }

            return response()->json([
                'calls' => $calls,
                'outer_request_is_current' => request() === $outer,
                'current_route' => Route::currentRouteName() ?? Route::current()?->uri(),
                'default_guard' => auth()->getDefaultDriver(),
            ]);
        });
    }

    /**
     * @param  list<array{method: string, path: string, json?: array<string, mixed>}>  $calls
     * @return array<string, mixed>
     */
    private function probe(User $user, array $calls): array
    {
        return $this->actingAs($user, 'web')
            ->postJson('/__portal-probe', ['calls' => $calls])
            ->assertOk()
            ->json();
    }

    public function test_it_calls_the_api_as_the_session_user_without_a_token(): void
    {
        $user = User::factory()->staff()->create();
        $staff = Staff::factory()->create(['user_id' => $user->id]);

        $result = $this->probe($user, [['method' => 'GET', 'path' => '/me/profile']]);

        $this->assertTrue($result['calls'][0]['ok']);
        $this->assertSame($user->public_id, $result['calls'][0]['body']['data']['user']['public_id']);
        $this->assertSame($staff->public_id, $result['calls'][0]['body']['data']['staff']['public_id']);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_the_calling_request_and_route_are_restored(): void
    {
        $user = User::factory()->staff()->create();

        $result = $this->probe($user, [['method' => 'GET', 'path' => '/me/home'], ['method' => 'GET', 'path' => '/me/profile']]);

        $this->assertTrue($result['outer_request_is_current']);
        $this->assertSame('__portal-probe', $result['current_route']);
        $this->assertSame('web', $result['default_guard'], 'the API\'s auth:sanctum must not leave its guard as the default');
        $this->assertTrue($result['calls'][1]['ok'], 'a second call in the same request works too');
    }

    public function test_api_permissions_decide(): void
    {
        $noRole = User::factory()->create();

        $result = $this->probe($noRole, [['method' => 'GET', 'path' => '/staff']]);

        $this->assertFalse($result['calls'][0]['ok']);
        $this->assertSame(403, $result['calls'][0]['status']);
    }

    public function test_a_suspended_account_is_refused_by_the_api_itself(): void
    {
        // The portal's own routes already sign such a user out; this pins
        // that the API's account.active also applies in-process.
        $user = User::factory()->staff()->suspended()->create();

        $result = $this->probe($user, [['method' => 'GET', 'path' => '/me/profile']]);

        $this->assertSame(403, $result['calls'][0]['status']);
        $this->assertSame('This account is not currently active.', $result['calls'][0]['message']);
    }

    public function test_validation_errors_come_back_field_by_field(): void
    {
        $user = User::factory()->staff()->create();
        Staff::factory()->create(['user_id' => $user->id]);

        $result = $this->probe($user, [['method' => 'POST', 'path' => '/me/leave-requests', 'json' => ['reason' => 'x']]]);

        $this->assertSame(422, $result['calls'][0]['status']);
        $this->assertArrayHasKey('leave_type_id', $result['calls'][0]['errors']);
        $this->assertArrayHasKey('start_date', $result['calls'][0]['errors']);
    }

    public function test_not_found_maps_to_404(): void
    {
        $user = User::factory()->staff()->create();

        $result = $this->probe($user, [['method' => 'GET', 'path' => '/staff/01NOTAREALID00000000000000']]);

        $this->assertSame(404, $result['calls'][0]['status']);
    }

    public function test_without_a_session_user_the_api_answers_401(): void
    {
        $this->expectException(PortalApiException::class);
        $this->expectExceptionCode(401);

        app(InternalApi::class)->get('/me/profile');
    }

    public function test_writes_through_the_portal_are_audited_as_web(): void
    {
        $admin = User::factory()->administrator()->create();
        $task = Task::factory()->create();

        $result = $this->probe($admin, [['method' => 'DELETE', 'path' => '/tasks/'.$task->public_id]]);

        $this->assertTrue($result['calls'][0]['ok']);
        $log = AuditLog::query()->where('action', AuditActions::TASK_DELETED)->sole();
        $this->assertSame(AuditSource::Web, $log->source);
        $this->assertSame($admin->id, $log->actor_user_id);
    }

    public function test_the_same_write_through_the_mobile_api_stays_api(): void
    {
        $admin = User::factory()->administrator()->create();
        $task = Task::factory()->create();

        $this->actingAs($admin, 'sanctum')->deleteJson('/api/v1/tasks/'.$task->public_id)->assertNoContent();

        $this->assertSame(AuditSource::Api, AuditLog::query()->where('action', AuditActions::TASK_DELETED)->sole()->source);
    }

    public function test_the_api_middleware_runs_even_when_the_caller_skips_middleware(): void
    {
        // Livewire's test harness runs component requests without
        // middleware; the API's sign-in and permission checks must still
        // apply to the in-process call, and the caller's setting is kept.
        $this->withoutMiddleware();
        $this->actingAs(User::factory()->create(), 'web');

        try {
            app(InternalApi::class)->get('/staff');
            $this->fail('A user without staff.view must be refused.');
        } catch (PortalApiException $e) {
            $this->assertSame(403, $e->status);
        }

        $this->assertTrue($this->app->bound('middleware.disable'));
    }

    public function test_a_client_cannot_mark_its_own_request_as_internal(): void
    {
        $request = Request::create('/api/v1/me/profile', 'GET', ['portal.internal' => true], server: ['HTTP_PORTAL_INTERNAL' => '1']);

        $this->assertFalse(InternalApi::isInternal($request));
    }
}
