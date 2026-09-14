<?php

namespace Tests\Feature\Api\V1\Audit;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Contact;
use App\Models\ServiceReport;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Redaction discipline (Phase 21, DEC-044): every audit entry created by
 * exercising a representative cross-section of the application must
 * never contain a password, a token, or free-text narrative/PII content
 * — only the curated, allowlisted structural fields each integration
 * site explicitly names.
 */
class AuditLogRedactionTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdministrator(): User
    {
        $user = User::factory()->administrator()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_failed_login_never_stores_the_attempted_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'super-secret-attempted-password',
        ])->assertUnprocessable();

        $this->assertNoAuditRowContains('super-secret-attempted-password');
    }

    public function test_no_audit_row_ever_contains_a_bearer_token_or_password_hash(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-password')]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertOk();

        $token = $response->json('data.token');
        $this->assertIsString($token);

        $this->assertNoAuditRowContains($token);
        $this->assertNoAuditRowContains($user->password);
    }

    public function test_staff_update_captures_only_the_curated_status_field_never_contact_details(): void
    {
        $this->actingAsAdministrator();
        $staff = Staff::factory()->create([
            'company_email' => 'sensitive-address@example.test',
            'company_phone' => '+1-555-000-9999',
        ]);

        $this->putJson("/api/v1/staff/{$staff->public_id}", ['status' => 'inactive'])->assertOk();

        $this->assertNoAuditRowContains('sensitive-address@example.test');
        $this->assertNoAuditRowContains('+1-555-000-9999');
    }

    public function test_contact_update_never_captures_personal_email_or_phone(): void
    {
        $this->actingAsAdministrator();
        $contact = Contact::factory()->create([
            'email' => 'contact-secret@example.test',
            'phone' => '+1-555-111-2222',
        ]);

        $this->putJson("/api/v1/contacts/{$contact->public_id}", ['status' => 'inactive'])->assertOk();

        $this->assertNoAuditRowContains('contact-secret@example.test');
        $this->assertNoAuditRowContains('+1-555-111-2222');
    }

    public function test_announcement_publish_never_captures_title_or_body_content(): void
    {
        $this->actingAsAdministrator();
        $announcement = \App\Models\Announcement::factory()->create([
            'title' => 'Confidential Reorg Announcement',
            'body' => 'Sensitive internal restructuring details go here.',
        ]);

        $this->postJson("/api/v1/announcements/{$announcement->public_id}/publish")->assertOk();

        $this->assertNoAuditRowContains('Confidential Reorg Announcement');
        $this->assertNoAuditRowContains('Sensitive internal restructuring details');
    }

    public function test_attachment_upload_never_captures_the_original_filename_or_file_contents(): void
    {
        Storage::fake('local');
        $this->actingAsAdministrator();
        $report = ServiceReport::factory()->create();

        $file = UploadedFile::fake()->image('patient-medical-record.jpg', 10, 10)->size(100);
        $this->postJson("/api/v1/service-reports/{$report->public_id}/attachments", ['file' => $file])->assertCreated();

        $this->assertNoAuditRowContains('patient-medical-record');
    }

    public function test_client_update_does_not_capture_business_contact_details(): void
    {
        $this->actingAsAdministrator();
        $client = Client::factory()->create(['email' => 'billing-secret@example.test']);

        $this->putJson("/api/v1/clients/{$client->public_id}", ['status' => 'inactive'])->assertOk();

        $this->assertNoAuditRowContains('billing-secret@example.test');
    }

    /**
     * No audit row anywhere — across actor/action/entity/changed_fields/
     * before/after/ip/user_agent/source — contains the given substring.
     */
    private function assertNoAuditRowContains(string $needle): void
    {
        $haystack = AuditLog::query()->get()->map(fn (AuditLog $log) => json_encode($log->getAttributes()))->implode("\n");

        $this->assertStringNotContainsString($needle, $haystack);
    }
}
