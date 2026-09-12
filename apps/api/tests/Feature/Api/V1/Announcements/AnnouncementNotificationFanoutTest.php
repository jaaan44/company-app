<?php

namespace Tests\Feature\Api\V1\Announcements;

use App\Enums\NotificationSourceType;
use App\Enums\NotificationType;
use App\Models\Announcement;
use App\Models\Department;
use App\Models\Notification;
use App\Models\Staff;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Coverage for the Phase 15 Notification fan-out wired into
 * AnnouncementController::publish() (NotifiesAnnouncementAudience) — a
 * publish-time snapshot, deliberately distinct from
 * ScopesAnnouncementVisibility's dynamic, current-membership feed. See
 * docs/phases/V1_PHASE_15_DEFINITION.md's Announcement Notification
 * Fan-out section.
 */
class AnnouncementNotificationFanoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function staffWithUser(?Department $department = null, ?Team $team = null): Staff
    {
        $user = User::factory()->staff()->create();

        return Staff::factory()->create([
            'user_id' => $user->id,
            'department_id' => $department?->id,
            'team_id' => $team?->id,
        ]);
    }

    private function publish(Announcement $announcement): void
    {
        $admin = User::factory()->administrator()->create();
        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/announcements/{$announcement->public_id}/publish")->assertOk();
    }

    public function test_a_company_wide_publish_notifies_every_staff_linked_account(): void
    {
        $a = $this->staffWithUser();
        $b = $this->staffWithUser();
        $announcement = Announcement::factory()->create();

        $this->publish($announcement);

        $this->assertDatabaseHas('notifications', ['recipient_user_id' => $a->user_id]);
        $this->assertDatabaseHas('notifications', ['recipient_user_id' => $b->user_id]);
        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_a_department_scoped_publish_notifies_only_that_departments_staff(): void
    {
        $department = Department::factory()->create();
        $otherDepartment = Department::factory()->create();
        $inScope = $this->staffWithUser($department);
        $outOfScope = $this->staffWithUser($otherDepartment);

        $announcement = Announcement::factory()->scoped()->create();
        $announcement->departments()->attach($department->id);

        $this->publish($announcement);

        $this->assertDatabaseHas('notifications', ['recipient_user_id' => $inScope->user_id]);
        $this->assertDatabaseMissing('notifications', ['recipient_user_id' => $outOfScope->user_id]);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_a_team_scoped_publish_notifies_only_that_teams_staff(): void
    {
        $team = Team::factory()->create();
        $otherTeam = Team::factory()->create();
        $inScope = $this->staffWithUser(null, $team);
        $outOfScope = $this->staffWithUser(null, $otherTeam);

        $announcement = Announcement::factory()->scoped()->create();
        $announcement->teams()->attach($team->id);

        $this->publish($announcement);

        $this->assertDatabaseHas('notifications', ['recipient_user_id' => $inScope->user_id]);
        $this->assertDatabaseMissing('notifications', ['recipient_user_id' => $outOfScope->user_id]);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_department_and_team_union_deduplicates_a_staff_member_matching_both(): void
    {
        $department = Department::factory()->create();
        $team = Team::factory()->create();
        $matchesBoth = $this->staffWithUser($department, $team);

        $announcement = Announcement::factory()->scoped()->create();
        $announcement->departments()->attach($department->id);
        $announcement->teams()->attach($team->id);

        $this->publish($announcement);

        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('notifications', ['recipient_user_id' => $matchesBoth->user_id]);
    }

    public function test_staff_without_a_linked_user_account_is_skipped(): void
    {
        Staff::factory()->create(['user_id' => null]);
        $announcement = Announcement::factory()->create();

        $this->publish($announcement);

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_notification_content_carries_no_announcement_body_and_a_generic_message(): void
    {
        $staff = $this->staffWithUser();
        $announcement = Announcement::factory()->create([
            'title' => 'Q3 Results',
            'body' => 'Confidential detail that should never appear in the notification text.',
        ]);

        $this->publish($announcement);

        $notification = Notification::query()->where('recipient_user_id', $staff->user_id)->firstOrFail();

        $this->assertSame('Q3 Results', $notification->title);
        $this->assertStringNotContainsString('Confidential detail', $notification->message);
        $this->assertSame(NotificationType::AnnouncementPublished, $notification->type);
        $this->assertSame(NotificationSourceType::Announcement, $notification->source_type);
        $this->assertSame($announcement->public_id, $notification->source_public_id);
    }

    public function test_archiving_a_published_announcement_creates_no_second_notification(): void
    {
        $staff = $this->staffWithUser();
        $announcement = Announcement::factory()->create();

        $this->publish($announcement);
        $this->assertDatabaseCount('notifications', 1);

        Sanctum::actingAs(User::factory()->administrator()->create());
        $this->postJson("/api/v1/announcements/{$announcement->public_id}/archive")->assertOk();

        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_a_later_department_change_does_not_alter_existing_notification_recipients(): void
    {
        $department = Department::factory()->create();
        $otherDepartment = Department::factory()->create();
        $staff = $this->staffWithUser($department);

        $announcement = Announcement::factory()->scoped()->create();
        $announcement->departments()->attach($department->id);
        $this->publish($announcement);

        $this->assertDatabaseCount('notifications', 1);

        // Moving out of the targeted department afterwards must not
        // retroactively remove the already-created Notification — it is a
        // snapshot of who qualified at publish time, not a live query.
        $staff->update(['department_id' => $otherDepartment->id]);

        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('notifications', ['recipient_user_id' => $staff->user_id]);
    }

    public function test_an_unrelated_staff_member_receives_nothing(): void
    {
        $department = Department::factory()->create();
        $otherDepartment = Department::factory()->create();
        $this->staffWithUser($department);
        $unrelated = $this->staffWithUser($otherDepartment);

        $announcement = Announcement::factory()->scoped()->create();
        $announcement->departments()->attach($department->id);

        $this->publish($announcement);

        $this->assertDatabaseMissing('notifications', ['recipient_user_id' => $unrelated->user_id]);
    }
}
