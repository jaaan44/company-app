<?php

namespace Tests\Feature\Portal;

use App\Enums\NotificationSourceType;
use App\Enums\NotificationType;
use App\Livewire\Portal\AnnouncementShow;
use App\Livewire\Portal\Chat;
use App\Livewire\Portal\Messages;
use App\Livewire\Portal\NewMessage;
use App\Livewire\Portal\Notifications;
use App\Models\Announcement;
use App\Models\AnnouncementAcknowledgement;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Message;
use App\Models\Notification;
use App\Models\Project;
use App\Models\Staff;
use App\Models\User;
use App\Services\Portal\InternalApi;
use App\Services\Portal\PortalApiException;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Staff Web Portal — Communication (Phase 34D): Messages (inbox, a chat,
 * New message), Notifications and Announcements, against the real API,
 * showing what the mobile screens show (Phase 32). "Today" is Tue 13 Oct
 * 2026 in Manila.
 */
class CommunicationPagesTest extends TestCase
{
    use RefreshDatabase;

    private const PAGES = ['/app/messages', '/app/messages/new', '/app/notifications', '/app/announcements'];

    private User $user;

    private Staff $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['scheduling.company_timezone' => 'Asia/Manila']);
        $this->travelTo(Carbon::parse('2026-10-13 02:00:00', 'UTC')); // Tue 13 Oct, 10:00 Manila

        $this->user = User::factory()->staff()->create();
        $this->staff = Staff::factory()->create(['user_id' => $this->user->id, 'first_name' => 'Lia', 'last_name' => 'Ramos', 'preferred_name' => null]);
    }

    private function colleague(string $first, string $last = 'Tan'): Staff
    {
        return Staff::factory()->create(['first_name' => $first, 'last_name' => $last, 'preferred_name' => null]);
    }

    /** @param list<Staff> $members */
    private function conversation(array $members, ?Conversation $conversation = null): Conversation
    {
        $conversation ??= Conversation::factory()->create();
        foreach ($members as $member) {
            ConversationMember::factory()->create(['conversation_id' => $conversation->id, 'staff_id' => $member->id]);
        }

        return $conversation;
    }

    private function message(Conversation $conversation, Staff $sender, string $body, string $utc): Message
    {
        $message = Message::factory()->create(['conversation_id' => $conversation->id, 'sender_staff_id' => $sender->id, 'body' => $body]);
        $message->created_at = Carbon::parse($utc, 'UTC');
        $message->save();

        return $message;
    }

    public function test_guests_are_sent_to_login_and_every_role_can_open_the_pages(): void
    {
        foreach (self::PAGES as $page) {
            $this->get($page)->assertRedirect('/login');
        }
        foreach ([$this->user, User::factory()->manager()->create(), User::factory()->administrator()->create(), User::factory()->create()] as $user) {
            $this->app['auth']->forgetGuards();
            foreach (self::PAGES as $page) {
                $this->actingAs($user, 'web')->get($page)->assertOk();
            }
        }
    }

    public function test_messages_is_a_tab_and_the_other_communication_pages_are_on_more(): void
    {
        $page = $this->actingAs($this->user, 'web')->get('/app/messages')
            ->assertSeeInOrder(['Home', 'Tasks', 'Schedule', 'Messages', 'More'])->getContent();
        $this->assertMatchesRegularExpression('#href="[^"]*/app/messages"\s+aria-current="page"#', $page);

        $this->get('/app/more')->assertSeeInOrder(['Announcements', 'Notifications']);
    }

    // ---- Messages ----------------------------------------------------------

    public function test_the_inbox_lists_my_conversations_newest_first_with_previews_and_unread_counts(): void
    {
        $kai = $this->colleague('Kai');
        $grace = $this->colleague('Grace', 'Hopper');
        // Created oldest activity first: the API orders by the newest message.
        $project = $this->conversation([$this->staff, $grace], Conversation::factory()->project()->create(['project_id' => Project::factory()->create(['name' => 'Plant 2 retrofit'])->id]));
        $mine = $this->message($project, $this->staff, 'Done for today', '2026-09-01 08:00:00');
        ConversationMember::query()->where('conversation_id', $project->id)->where('staff_id', $this->staff->id)->update(['last_read_message_id' => $mine->id]);
        $direct = $this->conversation([$this->staff, $kai]);
        $this->message($direct, $kai, "See you\nat the dock", '2026-10-12 10:40:00');
        $group = $this->conversation([$this->staff, $kai, $grace], Conversation::factory()->group()->create(['name' => 'Cold room crew']));
        $this->message($group, $kai, 'On my way', '2026-10-13 00:30:00');
        $this->message($group, $grace, 'Bring the gauges', '2026-10-13 01:12:00');
        $this->conversation([$this->staff], Conversation::factory()->group()->create(['name' => 'Quiet group']));
        $this->conversation([$kai, $grace], Conversation::factory()->group()->create(['name' => 'Not mine']));

        $this->actingAs($this->user, 'web')->get('/app/messages')->assertOk()
            ->assertSee('New message')
            ->assertSeeTextInOrder(['Cold room crew', 'Grace: Bring the gauges', '09:12', '2 unread', 'Kai Tan', 'See you at the dock', 'Yesterday', '1 unread', 'Plant 2 retrofit', 'You: Done for today', 'Tue 1 Sep'])
            ->assertSee('Quiet group')->assertSee('No messages yet')
            ->assertDontSee('Not mine');
    }

    public function test_messages_empty_and_no_profile(): void
    {
        $this->actingAs($this->user, 'web')->get('/app/messages')->assertSee('No conversations yet.');

        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->staff()->create(), 'web')->get('/app/messages')
            ->assertSee('No staff profile is linked to this account.')
            ->assertDontSee('href="'.route('portal.messages.new').'"', false);
    }

    public function test_a_chat_shows_messages_oldest_first_with_day_separators_and_marks_it_read(): void
    {
        $kai = $this->colleague('Kai');
        $grace = $this->colleague('Grace', 'Hopper');
        $group = $this->conversation([$this->staff, $kai, $grace], Conversation::factory()->group()->create(['name' => 'Cold room crew']));
        $this->message($group, $kai, 'Morning all', '2026-10-12 00:05:00');
        $this->message($group, $this->staff, "Hi Kai\nsee you", '2026-10-12 00:10:00');
        $last = $this->message($group, $grace, 'Bring the gauges', '2026-10-13 01:12:00');
        Notification::factory()->forRecipient($this->user)->create([
            'type' => NotificationType::MessageReceived, 'source_type' => NotificationSourceType::Conversation, 'source_public_id' => $group->public_id,
        ]);

        $this->actingAs($this->user, 'web')->get('/app/messages/'.$group->public_id)->assertOk()
            ->assertSee('Cold room crew')
            ->assertSeeInOrder(['Yesterday', 'Kai Tan', '08:05', 'Morning all', 'You', '08:10', 'Hi Kai', 'Today', 'Grace Hopper', '09:12', 'Bring the gauges'])
            ->assertSee('wire:poll.15s', false)
            ->assertDontSee('Load earlier messages');

        $member = ConversationMember::query()->where('conversation_id', $group->id)->where('staff_id', $this->staff->id)->sole();
        $this->assertSame($last->id, $member->last_read_message_id);
        $this->assertNotNull(Notification::query()->where('recipient_user_id', $this->user->id)->sole()->read_at);
    }

    public function test_sending_a_message_is_confirmed_and_empty_text_is_ignored(): void
    {
        $kai = $this->colleague('Kai');
        $kai->update(['user_id' => User::factory()->staff()->create()->id]);
        $direct = $this->conversation([$this->staff, $kai]);
        $this->actingAs($this->user, 'web');

        Livewire::test(Chat::class, ['publicId' => $direct->public_id])
            ->assertSee('No messages yet. Say hello.')
            ->assertSee('Kai Tan')
            ->set('body', '   ')->call('send')
            ->assertSet('sendError', null)
            ->assertHasNoErrors() // nothing was sent: the API would have refused it
            ->set('body', '  On my way  ')->call('send')
            ->assertSet('body', '')
            ->assertSet('sendError', null)
            ->assertSee('On my way');

        $this->assertSame(['On my way'], Message::query()->pluck('body')->all());
        $this->assertSame(1, Notification::query()->where('type', NotificationType::MessageReceived)->count()); // Kai's
    }

    public function test_earlier_messages_load_past_the_apis_100_per_page(): void
    {
        $kai = $this->colleague('Kai');
        $direct = $this->conversation([$this->staff, $kai]);
        foreach (range(1, 130) as $n) {
            $this->message($direct, $kai, sprintf('Message %03d', $n), Carbon::parse('2026-10-10 00:00:00', 'UTC')->addMinutes($n)->toDateTimeString());
        }
        $this->actingAs($this->user, 'web');

        $chat = Livewire::test(Chat::class, ['publicId' => $direct->public_id])
            ->assertSee('Message 130')->assertSee('Message 101')->assertDontSee('Message 100')->assertSee('Load earlier messages');
        foreach (range(2, 4) as $pages) {
            $chat->call('loadEarlier')->assertSet('pages', $pages);
        }
        $chat->assertSee('Message 011')->assertDontSee('Message 010')->assertSeeInOrder(['Message 011', 'Message 130'])
            ->call('loadEarlier')
            ->assertSee('Message 001')->assertDontSee('Load earlier messages');
    }

    public function test_a_failed_send_keeps_the_text(): void
    {
        $this->app->instance(InternalApi::class, new class extends InternalApi
        {
            public function __construct() {}

            public function send(string $method, string $path, array $query = [], array $json = []): array
            {
                if ($method === 'POST' && str_ends_with($path, '/messages')) {
                    throw new PortalApiException(500, 'Server Error');
                }

                return match (true) {
                    $path === '/me/profile' => ['data' => ['staff' => ['public_id' => 'ME1']]],
                    str_ends_with($path, '/messages') => ['data' => [], 'meta' => ['total' => 0, 'company_day' => ['date' => '2026-10-13', 'utc_offset' => '+08:00']]],
                    default => ['data' => ['public_id' => 'C1', 'type' => 'direct', 'members' => []]],
                };
            }
        });
        $this->actingAs($this->user, 'web');

        Livewire::test(Chat::class, ['publicId' => 'C1'])
            ->set('body', 'Hello there')->call('send')
            ->assertSet('body', 'Hello there')
            ->assertSee("Couldn't send. Try again.");
    }

    public function test_earlier_messages_load_thirty_at_a_time(): void
    {
        $kai = $this->colleague('Kai');
        $direct = $this->conversation([$this->staff, $kai]);
        foreach (range(1, 35) as $i) {
            $this->message($direct, $kai, "Message {$i}.", Carbon::parse('2026-10-13 00:00:00')->addMinutes($i)->toDateTimeString());
        }
        $this->actingAs($this->user, 'web');

        Livewire::test(Chat::class, ['publicId' => $direct->public_id])
            ->assertSee('Message 35.')->assertSee('Message 6.')->assertDontSee('Message 5.')
            ->assertSee('Load earlier messages')
            ->call('loadEarlier')
            ->assertSee('Message 1.')
            ->assertDontSee('Load earlier messages');
    }

    public function test_someone_elses_or_an_unknown_conversation(): void
    {
        $other = $this->conversation([$this->colleague('Kai'), $this->colleague('Zed')]);
        $this->message($other, Staff::query()->latest('id')->first(), 'Secret plans', '2026-10-13 00:00:00');

        $this->actingAs($this->user, 'web')->get('/app/messages/'.$other->public_id)->assertOk()
            ->assertSee('This conversation is no longer available.') // the API answers 404 to a non-member
            ->assertDontSee('Secret plans');
        $this->get('/app/messages/01ZZZZZZZZZZZZZZZZZZZZZZZZ')->assertOk()
            ->assertSee('This conversation is no longer available.');
    }

    public function test_new_message_lists_colleagues_without_me_and_opens_the_direct_chat(): void
    {
        $kai = $this->colleague('Kai');
        $this->colleague('Grace', 'Hopper');
        $this->actingAs($this->user, 'web');

        $page = Livewire::test(NewMessage::class)
            ->assertSee('Kai Tan')->assertSee('Grace Hopper')->assertDontSee('Lia Ramos')
            ->set('search', 'kai')
            ->assertSee('Kai Tan')->assertDontSee('Grace Hopper')
            ->call('start', $kai->public_id);

        $conversation = Conversation::query()->sole();
        $page->assertRedirect(route('portal.chat', $conversation->public_id));

        // Again: the same chat is found, not a second one made.
        Livewire::test(NewMessage::class)->call('start', $kai->public_id)->assertRedirect(route('portal.chat', $conversation->public_id));
        $this->assertSame(1, Conversation::query()->count());

        Livewire::test(NewMessage::class)->set('search', 'zzzz')->assertSee('No colleagues found.');
    }

    // ---- Notifications -----------------------------------------------------

    public function test_notifications_list_unread_bold_and_open_their_sources(): void
    {
        $announcement = Announcement::factory()->published()->create(['title' => 'Holiday hours']);
        $kai = $this->colleague('Kai');
        $chat = $this->conversation([$this->staff, $kai]);
        $first = Notification::factory()->forRecipient($this->user)->create(['title' => 'New announcement', 'source_type' => NotificationSourceType::Announcement, 'source_public_id' => $announcement->public_id, 'created_at' => '2026-10-12 03:00:00']);
        $second = Notification::factory()->forRecipient($this->user)->create(['type' => NotificationType::MessageReceived, 'title' => 'New message from Kai', 'message' => 'See you at the dock', 'source_type' => NotificationSourceType::Conversation, 'source_public_id' => $chat->public_id, 'created_at' => '2026-10-13 01:12:00']);
        Notification::factory()->forRecipient($this->user)->read()->create(['title' => 'Old news', 'created_at' => '2026-09-01 01:00:00']);
        Notification::factory()->create(['title' => 'Someone else’s']);

        $this->actingAs($this->user, 'web')->get('/app/notifications')->assertOk()
            ->assertSee('Mark all read')
            ->assertSeeInOrder(['Unread:', 'New message from Kai', 'See you at the dock', '09:12', 'Unread:', 'New announcement', 'Yesterday', 'Old news', 'Tue 1 Sep'])
            ->assertDontSee('Someone else');

        Livewire::test(Notifications::class)->call('open', $second->public_id)->assertRedirect(route('portal.chat', $chat->public_id));
        $this->assertNotNull($second->fresh()->read_at);
        Livewire::test(Notifications::class)->call('open', $first->public_id)->assertRedirect(route('portal.announcement', $announcement->public_id));
        $this->assertNotNull($first->fresh()->read_at);
    }

    public function test_mark_all_read_and_someone_elses_notification(): void
    {
        Notification::factory()->count(3)->forRecipient($this->user)->create();
        $foreign = Notification::factory()->create();
        $this->actingAs($this->user, 'web');

        Livewire::test(Notifications::class)
            ->call('open', $foreign->public_id)
            ->assertNoRedirect()
            ->assertSee('This notification is no longer available.')
            ->call('markAllRead')
            ->assertSee('All marked read.')
            ->assertDontSee('Mark all read</button>', false);

        $this->assertSame(0, Notification::query()->where('recipient_user_id', $this->user->id)->whereNull('read_at')->count());
        $this->assertNull($foreign->fresh()->read_at);
    }

    public function test_notifications_work_without_a_profile_and_empty(): void
    {
        $noProfile = User::factory()->staff()->create();
        Notification::factory()->forRecipient($noProfile)->create(['title' => 'Welcome aboard']);

        $this->actingAs($noProfile, 'web')->get('/app/notifications')->assertSee('Welcome aboard')->assertDontSee('No staff profile');

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->user, 'web')->get('/app/notifications')->assertSee('No notifications yet.')->assertDontSee('Mark all read');
    }

    // ---- Announcements -----------------------------------------------------

    public function test_announcements_list_newest_first_with_new_until_acknowledged(): void
    {
        Announcement::factory()->published()->create(['title' => 'Older notice', 'published_at' => '2026-10-01 01:00:00']);
        $acknowledged = Announcement::factory()->published()->create(['title' => 'Read notice', 'published_at' => '2026-10-05 01:00:00']);
        AnnouncementAcknowledgement::factory()->create(['announcement_id' => $acknowledged->id, 'staff_id' => $this->staff->id]);
        Announcement::factory()->create(['title' => 'Draft notice']);

        $this->actingAs($this->user, 'web')->get('/app/announcements')->assertOk()
            ->assertSeeInOrder(['Read notice', '5 Oct 2026', 'Older notice', '1 Oct 2026', 'New'])
            ->assertDontSee('Draft notice');
    }

    public function test_an_announcement_and_acknowledging_it(): void
    {
        $publisher = User::factory()->administrator()->create();
        Staff::factory()->create(['user_id' => $publisher->id, 'first_name' => 'Grace', 'last_name' => 'Hopper', 'preferred_name' => null]);
        $announcement = Announcement::factory()->published()->create([
            'title' => 'Holiday hours', 'body' => "The office closes at 3 pm.\nEnjoy the weekend.",
            'published_at' => '2026-10-12 01:30:00', 'published_by_user_id' => $publisher->id,
        ]);

        $this->actingAs($this->user, 'web')->get('/app/announcements/'.$announcement->public_id)->assertOk()
            ->assertSee('Holiday hours')
            ->assertSee('Grace Hopper · 12 Oct 2026 09:30')
            ->assertSee('The office closes at 3 pm.')
            ->assertSee('Acknowledge');

        Livewire::test(AnnouncementShow::class, ['publicId' => $announcement->public_id])
            ->call('acknowledge')
            ->assertSee('Acknowledged on 13 Oct 2026 10:00')
            ->assertDontSee('>Acknowledge<', false);

        $this->assertSame(1, AnnouncementAcknowledgement::query()->where('announcement_id', $announcement->id)->where('staff_id', $this->staff->id)->count());
    }

    public function test_an_unknown_announcement_and_no_profile(): void
    {
        $this->actingAs($this->user, 'web')->get('/app/announcements/01ZZZZZZZZZZZZZZZZZZZZZZZZ')->assertOk()
            ->assertSee('This announcement is no longer available.');

        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->staff()->create(), 'web')->get('/app/announcements')
            ->assertSee('No staff profile is linked to this account.');
    }

    // ---- Home --------------------------------------------------------------

    public function test_home_links_messages_notifications_and_announcements(): void
    {
        $announcement = Announcement::factory()->published()->create(['title' => 'Holiday hours']);

        $this->actingAs($this->user, 'web')->get('/app')->assertOk()
            ->assertSee('href="'.route('portal.messages').'" class="block rounded-xl', false)
            ->assertSee('href="'.route('portal.notifications').'" class="block rounded-xl', false)
            ->assertSee(route('portal.announcement', $announcement->public_id), false)
            // Mobile redesign: "View all", heard as "View all announcements" (Livewire puts a comment marker between the two).
            ->assertSeeInOrder(['href="'.route('portal.announcements').'"', 'View all', '<span class="sr-only"> announcements</span>'], false);
    }

    public function test_an_ended_session_goes_to_login(): void
    {
        $this->app->instance(InternalApi::class, new class extends InternalApi
        {
            public function __construct() {}

            public function send(string $method, string $path, array $query = [], array $json = []): array
            {
                throw new PortalApiException(401, 'Unauthenticated.');
            }
        });
        $this->actingAs($this->user, 'web');

        foreach ([Messages::class, Notifications::class, NewMessage::class] as $component) {
            Livewire::test($component)->assertRedirect(route('login'));
        }
    }
}
