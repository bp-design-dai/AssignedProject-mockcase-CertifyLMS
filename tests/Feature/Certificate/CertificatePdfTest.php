<?php

declare(strict_types=1);

namespace Tests\Feature\Certificate;

use App\Enums\EnrollmentStatus;
use App\Models\Certificate;
use App\Models\Certification;
use App\Models\CertificationCoachAssignment;
use App\Models\Enrollment;
use App\Models\MockExam;
use App\Models\MockExamSession;
use App\Models\User;
use App\UseCases\Certificate\IssueAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CertificatePdfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
        Storage::fake('public');
    }

    public function test_receipt_creates_private_pdf_and_downloads_the_same_bytes(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 5));
        $enrollment = $this->eligibleEnrollment();
        $receive = route('enrollments.receiveCertificate', $enrollment);
        $this->actingAs($enrollment->user)->post($receive)->assertRedirect(route('enrollments.show', $enrollment));

        $certificate = Certificate::sole();
        $this->assertSame(EnrollmentStatus::Passed, $enrollment->fresh()->status);
        Storage::disk('private')->assertExists($certificate->pdf_path);
        Storage::disk('public')->assertMissing($certificate->pdf_path);
        $bytes = Storage::disk('private')->get($certificate->pdf_path);
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertStringEndsWith('%%EOF', rtrim($bytes));
        $this->assertGreaterThan(1000, strlen($bytes));

        $download = $this->get(route('certificates.download', $certificate))->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')->assertDownload('certificate.pdf');
        $this->assertSame($bytes, $download->streamedContent());

        if (getenv('SA04_PDF_EVIDENCE') === '1') {
            file_put_contents('/tmp/sa04-synthetic-certificate.pdf', $bytes);
        }

        $this->post($receive)->assertRedirect(route('enrollments.show', $enrollment))
            ->assertSessionHas('success', '修了証は発行済みです。この画面からダウンロードできます。');
        $this->assertDatabaseCount('certificates', 1);
        $this->assertSame(1, $enrollment->statusLogs()->where('to_status', EnrollmentStatus::Passed)->count());
        $this->assertSame([$certificate->pdf_path], Storage::disk('private')->allFiles('certificates'));
        $this->assertSame($bytes, Storage::disk('private')->get($certificate->pdf_path));
        $this->get('/storage/'.$certificate->pdf_path)->assertNotFound();
    }

    public function test_render_failure_preserves_unissued_state_and_allows_retry(): void
    {
        $enrollment = $this->eligibleEnrollment();
        $origin = route('enrollments.show', $enrollment);
        $font = config('certificates.pdf_font_path');
        config(['certificates.pdf_font_path' => storage_path('missing-japanese-font.ttf')]);

        $this->actingAs($enrollment->user)->from($origin)
            ->post(route('enrollments.receiveCertificate', $enrollment))
            ->assertRedirect($origin)
            ->assertSessionHas('error', '修了証PDFを作成できませんでした。時間をおいて再度受領してください。');

        $this->assertUnissued($enrollment);

        config(['certificates.pdf_font_path' => $font]);
        $this->post(route('enrollments.receiveCertificate', $enrollment))->assertRedirect($origin);
        $certificate = Certificate::sole();
        Storage::disk('private')->assertExists($certificate->pdf_path);
        $this->assertSame(EnrollmentStatus::Passed, $enrollment->fresh()->status);
    }

    public function test_partial_file_from_failed_storage_is_removed(): void
    {
        $enrollment = $this->eligibleEnrollment();
        $disk = Storage::disk('private');
        $partial = \Mockery::mock($disk)->makePartial();
        $partial->shouldReceive('put')->once()->andReturnUsing(function ($path) use ($disk) {
            $disk->put($path, 'incomplete');

            return false;
        });
        Storage::set('private', $partial);

        $this->actingAs($enrollment->user)->postJson(route('enrollments.receiveCertificate', $enrollment))
            ->assertStatus(503);
        $this->assertUnissued($enrollment);
    }

    public function test_failed_record_insert_removes_pdf_and_rolls_back_enrollment(): void
    {
        $enrollment = $this->eligibleEnrollment();
        $dispatcher = Certificate::getEventDispatcher();
        Certificate::setEventDispatcher(clone $dispatcher);

        try {
            Certificate::creating(function (Certificate $certificate): never {
                Storage::disk('private')->assertExists($certificate->pdf_path);

                throw new RuntimeException('Simulated certificate insert failure.');
            });
            $this->actingAs($enrollment->user)->postJson(route('enrollments.receiveCertificate', $enrollment))
                ->assertStatus(500);
        } finally {
            Certificate::setEventDispatcher($dispatcher);
        }

        $this->assertUnissued($enrollment);
    }

    #[DataProvider('downloadRoles')]
    public function test_download_authorization(string $role, int $status): void
    {
        $enrollment = Enrollment::factory()->passed()->create();
        $certificate = app(IssueAction::class)($enrollment);
        $user = match ($role) {
            'owner' => $enrollment->user,
            'graduated' => tap($enrollment->user, fn (User $user) => $user->update(['status' => 'graduated'])),
            'withdrawn' => tap($enrollment->user, fn (User $user) => $user->update(['status' => 'withdrawn'])),
            'other' => User::factory()->student()->create(),
            'admin' => User::factory()->admin()->create(),
            default => User::factory()->coach()->create(),
        };

        if (in_array($role, ['assigned', 'unassigned'], true)) {
            CertificationCoachAssignment::factory()->create([
                'certification_id' => $certificate->certification_id,
                'user_id' => $user->id,
                'unassigned_at' => $role === 'unassigned' ? now() : null,
            ]);
        }

        $response = $this->actingAs($user)->get(route('certificates.download', $certificate))->assertStatus($status);
        if ($status === 200) {
            $response->assertDownload('certificate.pdf')->assertHeader('Content-Type', 'application/pdf');
            $this->assertSame(Storage::disk('private')->get($certificate->pdf_path), $response->streamedContent());
        }
    }

    public static function downloadRoles(): array
    {
        return [
            ['owner', 200], ['graduated', 200], ['withdrawn', 403], ['other', 403],
            ['admin', 200], ['assigned', 200], ['unassigned', 403], ['outside', 403],
        ];
    }

    public function test_legacy_record_returns_404_without_creating_or_updating_a_file(): void
    {
        $enrollment = Enrollment::factory()->passed()->create();
        $certificate = Certificate::factory()->forEnrollment($enrollment)->create();
        $before = $certificate->fresh()->getAttributes();
        $download = route('certificates.download', $certificate);

        $this->get($download)->assertRedirect(route('login'));
        $this->actingAs($enrollment->user)->get($download)->assertNotFound();
        $this->post(route('enrollments.receiveCertificate', $enrollment))
            ->assertRedirect(route('enrollments.show', $enrollment));
        $this->get($download)->assertNotFound();
        $this->assertSame($before, $certificate->fresh()->getAttributes());
        $this->assertSame([], Storage::disk('private')->allFiles());
        $this->assertDatabaseCount('certificates', 1);
    }

    private function eligibleEnrollment(): Enrollment
    {
        $student = User::factory()->student()->inProgress()->create(['name' => '検証用 山田 花子']);
        $certification = Certification::factory()->published()->create([
            'name' => '検証用 情報技術資格',
            'description' => 'SA04-NOT-ON-PDF',
        ]);
        $enrollment = Enrollment::factory()->for($student)->for($certification)->learning()->create();
        $exam = MockExam::factory()->for($certification)->create(['is_published' => true]);
        MockExamSession::factory()->for($enrollment)->for($exam)->create(['pass' => true]);

        return $enrollment;
    }

    private function assertUnissued(Enrollment $enrollment): void
    {
        $this->assertDatabaseCount('certificates', 0);
        $this->assertSame(EnrollmentStatus::Learning, $enrollment->fresh()->status);
        $this->assertNull($enrollment->fresh()->passed_at);
        $this->assertSame(0, $enrollment->statusLogs()->where('to_status', EnrollmentStatus::Passed)->count());
        $this->assertSame([], Storage::disk('private')->allFiles());
    }
}
