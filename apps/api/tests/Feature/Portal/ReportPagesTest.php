<?php

namespace Tests\Feature\Portal;

use App\Enums\AuditSource;
use App\Enums\IncidentReportStatus;
use App\Enums\IncidentReportType;
use App\Enums\IncidentSeverity;
use App\Enums\ProjectStatus;
use App\Enums\ServiceReportStatus;
use App\Livewire\Portal\IncidentForm;
use App\Livewire\Portal\Reports;
use App\Livewire\Portal\ReportShow;
use App\Livewire\Portal\ServiceReportForm;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\IncidentReport;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\ServiceReport;
use App\Models\Staff;
use App\Models\User;
use App\Support\Audit\AuditActions;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Staff Web Portal — Reports (Phase 34E): service reports and incidents
 * with their photos and files, against the real API, showing what the
 * mobile screens show (Phase 33), plus browser uploads of JPEG, PNG and
 * PDF (W-8). "Today" is Tue 13 Oct 2026 in Manila.
 */
class ReportPagesTest extends TestCase
{
    use RefreshDatabase;

    private const PAGES = ['/app/reports', '/app/reports?type=incidents', '/app/reports/service/new', '/app/reports/incidents/new'];

    private User $user;

    private Staff $staff;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
        config(['scheduling.company_timezone' => 'Asia/Manila']);
        $this->travelTo(Carbon::parse('2026-10-13 02:00:00', 'UTC')); // Tue 13 Oct, 10:00 Manila

        $this->user = User::factory()->staff()->create();
        $this->staff = Staff::factory()->create(['user_id' => $this->user->id, 'first_name' => 'Lia', 'last_name' => 'Ramos', 'preferred_name' => null]);
        $this->client = Client::factory()->create(['name' => 'Harbour Foods', 'client_code' => 'HBF']);
    }

    /** @param array<string, mixed> $attributes */
    private function draft(array $attributes = []): ServiceReport
    {
        return ServiceReport::factory()->create($attributes + ['creator_staff_id' => $this->staff->id, 'client_id' => $this->client->id, 'service_date' => '2026-10-12', 'work_performed' => 'Replaced the seals.']);
    }

    private function attachment(ServiceReport $report, string $name, string $mime, string $content): Attachment
    {
        $path = 'service-reports/'.$report->public_id.'/'.$name;
        Storage::disk('local')->put($path, $content);

        return Attachment::factory()->create(['service_report_id' => $report->id, 'original_filename' => $name, 'mime_type' => $mime, 'storage_path' => $path, 'size_bytes' => strlen($content)]);
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

    public function test_reports_are_on_more(): void
    {
        $this->actingAs($this->user, 'web')->get('/app/more')->assertSeeInOrder(['Notifications', 'Reports', 'Leave']);
        $page = $this->get('/app/reports')->assertSee('aria-label="Back to More"', false)->getContent();
        $this->assertMatchesRegularExpression('#href="[^"]*/app/more"\s+aria-current="page"#', $page);
    }

    // ---- Lists -------------------------------------------------------------

    public function test_the_lists_show_my_reports_with_chips(): void
    {
        $project = Project::factory()->create(['name' => 'Cold room upgrade']);
        $this->draft(['service_date' => '2026-10-12', 'project_id' => $project->id]);
        $this->draft(['service_date' => '2026-10-01', 'status' => ServiceReportStatus::Rejected]);
        ServiceReport::factory()->create(['client_id' => Client::factory()->create(['name' => 'Someone else’s client'])->id]);
        IncidentReport::factory()->create(['reporter_staff_id' => $this->staff->id, 'incident_type' => IncidentReportType::WorkplaceSafety, 'severity' => IncidentSeverity::High, 'occurred_at' => '2026-10-13 01:30:00', 'location' => 'Dock 2']);

        $this->actingAs($this->user, 'web')->get('/app/reports')->assertOk()
            ->assertSee('New service report')
            ->assertSeeInOrder(['Harbour Foods', 'Mon 12 Oct 2026 · Cold room upgrade', 'Draft', 'Harbour Foods', 'Thu 1 Oct 2026', 'Rejected'])
            ->assertDontSee('Someone else');

        Livewire::test(Reports::class)->call('show', 'incidents')
            ->assertSee('Report an incident')
            ->assertSeeInOrder(['Workplace safety', 'Tue 13 Oct 2026 09:30', 'Dock 2', 'High', 'Reported']);
    }

    public function test_empty_lists_and_no_profile(): void
    {
        $this->actingAs($this->user, 'web')->get('/app/reports')->assertSee("You haven't written a service report yet.");
        Livewire::test(Reports::class)->call('show', 'incidents')->assertSee('No incidents reported.');

        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->staff()->create(), 'web')->get('/app/reports')
            ->assertSee('No staff profile is linked to this account.')
            ->assertDontSee('New service report');
    }

    // ---- Service report form -----------------------------------------------

    public function test_the_service_form_checks_what_the_mobile_form_checks(): void
    {
        $this->actingAs($this->user, 'web');

        Livewire::test(ServiceReportForm::class)
            ->assertSet('serviceDate', '2026-10-13')
            ->set('serviceDate', '2026-10-14')
            ->set('siteRepresentative', str_repeat('a', 256))
            ->call('save')
            ->assertSee('Choose a client.')
            ->assertSee('The service date cannot be later than today.')
            ->assertSee('Describe the work performed.')
            ->assertSee('Use at most 255 characters.')
            ->set('serviceDate', '')
            ->call('save')
            ->assertSee('Choose the service date.');

        $this->assertSame(0, ServiceReport::query()->count());
    }

    public function test_choosing_a_client_and_project_and_saving_a_draft(): void
    {
        $mine = Project::factory()->create(['name' => 'Cold room upgrade', 'client_id' => $this->client->id, 'status' => ProjectStatus::Active]);
        ProjectMembership::factory()->create(['project_id' => $mine->id, 'staff_id' => $this->staff->id]);
        $closed = Project::factory()->create(['name' => 'Old fit-out', 'client_id' => $this->client->id, 'status' => ProjectStatus::Completed]);
        ProjectMembership::factory()->create(['project_id' => $closed->id, 'staff_id' => $this->staff->id]);
        Project::factory()->create(['name' => 'Not my project', 'client_id' => $this->client->id]);
        Client::factory()->inactive()->create(['name' => 'Harbour Old']);
        $this->actingAs($this->user, 'web');

        $form = Livewire::test(ServiceReportForm::class)
            ->set('clientSearch', 'harbour')
            ->assertSee('Harbour Foods')->assertDontSee('Harbour Old')
            ->call('chooseClient', $this->client->public_id)
            ->assertSet('clientName', 'Harbour Foods')
            ->assertSee('Cold room upgrade')->assertDontSee('Old fit-out')->assertDontSee('Not my project')
            ->set('project', $mine->public_id)
            ->set('serviceDate', '2026-10-12')
            ->set('workPerformed', '  Replaced both door seals.  ')
            ->set('findings', '   ')
            ->call('save')
            ->assertHasNoErrors();

        $report = ServiceReport::query()->sole();
        $form->assertRedirect(route('portal.service-report', $report->public_id));
        $this->assertSame(ServiceReportStatus::Draft, $report->status);
        $this->assertSame($this->staff->id, $report->creator_staff_id);
        $this->assertSame($mine->id, $report->project_id);
        $this->assertSame('Replaced both door seals.', $report->work_performed);
        $this->assertNull($report->findings);
        $this->assertSame('Draft saved.', session('status'));
    }

    public function test_editing_my_draft_only(): void
    {
        $draft = $this->draft(['findings' => 'Worn gasket']);
        $submitted = $this->draft(['status' => ServiceReportStatus::Submitted]);
        $this->actingAs($this->user, 'web');

        Livewire::test(ServiceReportForm::class, ['publicId' => $draft->public_id])
            ->assertSet('clientName', 'Harbour Foods')->assertSet('findings', 'Worn gasket')
            ->set('workPerformed', 'Replaced the seals and tested.')
            ->call('save')
            ->assertRedirect(route('portal.service-report', $draft->public_id));
        $this->assertSame('Replaced the seals and tested.', $draft->fresh()->work_performed);

        $this->get('/app/reports/service/'.$submitted->public_id.'/edit')->assertOk()->assertSee('This draft is no longer available.');
    }

    // ---- Service report page -----------------------------------------------

    public function test_my_draft_shows_its_actions_and_submit_is_confirmed_by_the_api(): void
    {
        $draft = $this->draft(['recommendations' => "Replace the hinge.\nCheck again in March."]);
        $this->actingAs($this->user, 'web');

        $this->get('/app/reports/service/'.$draft->public_id)->assertOk()
            ->assertSee('Service report · Harbour Foods')
            ->assertSee('Mon 12 Oct 2026')->assertSee('No project')->assertSee('Lia Ramos')
            ->assertSeeInOrder(['Work performed', 'Replaced the seals.', 'Recommendations', 'Replace the hinge.'])
            ->assertSee('Edit')->assertSee('Submit')->assertSee('Delete draft')->assertDontSee('Return to draft')
            ->assertSeeInOrder(['Submit this report?', "Your manager will review it. You can't edit it after submitting."])
            ->assertSee('<dialog', false)->assertSee('$refs.dialog.close(); $wire.submitReport()', false)
            ->assertSee('Photos and files (0)')->assertSee('It uploads as soon as you choose it.');

        Livewire::test(ReportShow::class, ['publicId' => $draft->public_id])
            ->call('submitReport')
            ->assertSee('Report submitted.')
            ->assertSee('Submitted')
            ->assertDontSee('Delete draft')->assertDontSee('Add a photo or PDF');

        $this->assertSame(ServiceReportStatus::Submitted, $draft->fresh()->status);
    }

    public function test_deleting_a_draft_and_returning_a_rejected_report(): void
    {
        $draft = $this->draft();
        $rejected = $this->draft(['status' => ServiceReportStatus::Rejected]);
        $this->actingAs($this->user, 'web');

        Livewire::test(ReportShow::class, ['publicId' => $draft->public_id])
            ->call('deleteDraft')
            ->assertRedirect(route('portal.reports'));
        $this->assertNull(ServiceReport::query()->find($draft->id));

        Livewire::test(ReportShow::class, ['publicId' => $rejected->public_id])
            ->assertSee('Return to draft')->assertDontSee('Delete draft')
            ->call('returnToDraft')
            ->assertSee('Returned to draft.')
            ->assertSee('Delete draft');
        $this->assertSame(ServiceReportStatus::Draft, $rejected->fresh()->status);
    }

    public function test_my_manager_can_read_my_draft_but_not_act_on_it(): void
    {
        $managerUser = User::factory()->manager()->create();
        $manager = Staff::factory()->create(['user_id' => $managerUser->id]);
        $this->staff->update(['manager_id' => $manager->id]);
        $draft = $this->draft();
        $rejected = $this->draft(['status' => ServiceReportStatus::Rejected]);

        $this->actingAs($managerUser, 'web')->get('/app/reports/service/'.$draft->public_id)->assertOk()
            ->assertSee('Replaced the seals.')
            ->assertDontSee(route('portal.service-report.edit', $draft->public_id), false)
            ->assertDontSee('Delete draft')->assertDontSee('Add a photo or PDF');
        $this->get('/app/reports/service/'.$rejected->public_id)->assertOk()->assertDontSee('Return to draft');
    }

    public function test_someone_elses_or_an_unknown_report(): void
    {
        $other = ServiceReport::factory()->create(['work_performed' => 'Secret work']);

        $this->actingAs($this->user, 'web')->get('/app/reports/service/'.$other->public_id)->assertOk()
            ->assertDontSee('Secret work')
            ->assertSee('This report is no longer available.'); // the API answers 404 to someone who can't see it
        $this->get('/app/reports/service/01ZZZZZZZZZZZZZZZZZZZZZZZZ')->assertOk()->assertSee('This report is no longer available.');
    }

    // ---- Files -------------------------------------------------------------

    public function test_uploading_a_photo_and_a_pdf_and_removing_one(): void
    {
        $draft = $this->draft();
        $this->actingAs($this->user, 'web');

        $page = Livewire::test(ReportShow::class, ['publicId' => $draft->public_id])
            // Phase 37: choosing a file uploads it; no separate Upload tap.
            ->set('file', UploadedFile::fake()->image('door.jpg', 640, 480))
            ->assertHasNoErrors()
            ->assertSee('File added.')
            ->assertSee('door.jpg')
            ->set('file', UploadedFile::fake()->createWithContent('quote.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n"))
            ->assertSee('quote.pdf')
            ->assertSee('Photos and files (2)');

        $photo = Attachment::query()->where('original_filename', 'door.jpg')->sole();
        $this->assertSame('image/jpeg', $photo->mime_type);
        Storage::disk('local')->assertExists($photo->storage_path);
        $this->assertSame(AuditSource::Web, AuditLog::query()->where('action', AuditActions::ATTACHMENT_UPLOADED)->where('entity_public_id', $photo->public_id)->sole()->source);

        $page->call('removeFile', $photo->public_id)
            ->assertSee('File removed.')
            ->assertDontSee('door.jpg')
            ->assertSee('Photos and files (1)');
        $this->assertSame(['quote.pdf'], Attachment::query()->pluck('original_filename')->all());
    }

    public function test_the_file_must_be_a_photo_or_pdf_of_at_most_10_mb(): void
    {
        $draft = $this->draft();
        $this->actingAs($this->user, 'web');

        Livewire::test(ReportShow::class, ['publicId' => $draft->public_id])
            ->call('addFile')
            ->assertHasErrors(['file'])->assertSee('Choose a photo or PDF first.')
            ->set('file', UploadedFile::fake()->create('notes.txt', 3, 'text/plain'))
            ->call('addFile')
            ->assertSee('Choose a JPEG, PNG or PDF file.')
            ->set('file', UploadedFile::fake()->image('huge.jpg')->size(10241))
            ->call('addFile')
            ->assertSee('The file is larger than 10 MB.');

        $this->assertSame(0, Attachment::query()->count());
    }

    public function test_files_open_or_download_through_the_portal_for_allowed_viewers_only(): void
    {
        $draft = $this->draft();
        $photo = $this->attachment($draft, 'door.jpg', 'image/jpeg', UploadedFile::fake()->image('x.jpg')->getContent());
        $pdf = $this->attachment($draft, 'quote.pdf', 'application/pdf', '%PDF-1.4 test');
        $photoUrl = route('portal.report-file', ['kind' => 'service', 'publicId' => $draft->public_id, 'attachmentId' => $photo->public_id]);
        $pdfUrl = route('portal.report-file', ['kind' => 'service', 'publicId' => $draft->public_id, 'attachmentId' => $pdf->public_id]);

        $this->get($photoUrl)->assertRedirect('/login');

        $this->actingAs($this->user, 'web');
        $this->get('/app/reports/service/'.$draft->public_id)->assertSee($photoUrl, false)->assertSee('Open photo')->assertSee('Download');
        $image = $this->get($photoUrl)->assertOk();
        $this->assertStringStartsWith('inline;', (string) $image->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $image->headers->get('X-Content-Type-Options'));
        $download = $this->get($pdfUrl)->assertOk();
        $this->assertStringStartsWith('attachment;', (string) $download->headers->get('Content-Disposition'));
        $this->assertStringContainsString('quote.pdf', (string) $download->headers->get('Content-Disposition'));

        // Another staff member can't read it, and a wrong report/file pair is not found.
        $this->app['auth']->forgetGuards();
        $other = User::factory()->staff()->create();
        Staff::factory()->create(['user_id' => $other->id]);
        $this->actingAs($other, 'web')->get($photoUrl)->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->user, 'web')
            ->get(route('portal.report-file', ['kind' => 'service', 'publicId' => $this->draft()->public_id, 'attachmentId' => $photo->public_id]))
            ->assertNotFound();
    }

    // ---- Incidents ---------------------------------------------------------

    public function test_reporting_an_incident(): void
    {
        $this->actingAs($this->user, 'web');

        $form = Livewire::test(IncidentForm::class)
            // Phase 37: the long fields are multi-line, the severity choices thumb-sized.
            ->assertSeeHtml('<textarea id="description"')->assertSeeHtml('<textarea id="immediate"')
            ->assertSeeHtml('<textarea id="people"')->assertSeeHtml('<textarea id="witnesses"')
            ->assertSeeHtml('<input id="location" type="text"')
            ->assertSeeHtml('inline-flex min-h-11 items-center gap-2 rounded-full')
            ->assertSet('date', '2026-10-13')->assertSet('time', '10:00')->assertSet('severity', 'medium')
            ->set('time', '11:30')
            ->call('save')
            ->assertSee('Choose the type of incident.')
            ->assertSee('The time cannot be in the future.')
            ->assertSee('Describe what happened.')
            ->set('type', 'workplace_safety')->set('severity', 'high')->set('time', '09:15')
            ->set('location', 'Dock 2')->set('description', '  A pallet fell from the rack.  ')
            ->call('save')
            ->assertHasNoErrors();

        $incident = IncidentReport::query()->sole();
        $form->assertRedirect(route('portal.incident', $incident->public_id));
        $this->assertSame(IncidentReportStatus::Reported, $incident->status);
        $this->assertSame(IncidentSeverity::High, $incident->severity);
        $this->assertSame('2026-10-13 01:15:00', $incident->occurred_at->utc()->toDateTimeString());
        $this->assertSame('A pallet fell from the rack.', $incident->description);
        $this->assertSame($this->staff->id, $incident->reporter_staff_id);
    }

    public function test_an_incident_page_and_editing_only_while_reported(): void
    {
        $assignee = Staff::factory()->create(['first_name' => 'Ben', 'last_name' => 'Cruz', 'preferred_name' => null]);
        $reported = IncidentReport::factory()->create(['reporter_staff_id' => $this->staff->id, 'incident_type' => IncidentReportType::ClientSite, 'occurred_at' => '2026-10-12 23:45:00', 'location' => 'Gate B', 'description' => 'Wet floor.']);
        $resolved = IncidentReport::factory()->resolved()->create(['reporter_staff_id' => $this->staff->id, 'assigned_to_staff_id' => $assignee->id, 'resolution' => 'Mat installed.']);
        $this->actingAs($this->user, 'web');

        $this->get('/app/reports/incidents/'.$reported->public_id)->assertOk()
            ->assertSee('Client site')->assertSee('Medium')->assertSee('Reported')
            ->assertSee('Tue 13 Oct 2026 07:45')->assertSee('Gate B')->assertSee('Lia Ramos')
            ->assertSee('What happened')->assertSee('Wet floor.')
            ->assertSee(route('portal.incident.edit', $reported->public_id), false)
            ->assertDontSee('Submit')->assertDontSee('Delete draft');

        Livewire::test(IncidentForm::class, ['publicId' => $reported->public_id])
            ->assertSet('date', '2026-10-13')->assertSet('time', '07:45')->assertSet('type', 'client_site')
            ->set('location', 'Gate C')
            ->call('save')
            ->assertRedirect(route('portal.incident', $reported->public_id));
        $this->assertSame('Gate C', $reported->fresh()->location);

        $this->get('/app/reports/incidents/'.$resolved->public_id)->assertOk()
            ->assertSee('Resolved')->assertSee('Assigned to')->assertSee('Ben Cruz')->assertSee('Mat installed.')
            ->assertDontSee(route('portal.incident.edit', $resolved->public_id), false)
            ->assertDontSee('Upload');
        $this->get('/app/reports/incidents/'.$resolved->public_id.'/edit')->assertOk()
            ->assertSee('This incident can no longer be edited here.');
    }
}
