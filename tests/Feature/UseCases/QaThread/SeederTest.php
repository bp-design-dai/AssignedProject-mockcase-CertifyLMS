<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\QaThread;

use App\Enums\QaThreadStatus;
use App\Models\Certification;
use App\Models\QaReply;
use App\Models\QaThread;
use App\Models\User;
use Database\Seeders\QaBoardSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_data_covers_published_certifications_and_does_not_overwrite_edits(): void
    {
        $student = User::factory()->student()->create(['email' => 'student@certify-lms.test']);
        Certification::factory()->published()->count(2)->create();
        Certification::factory()->archived()->create();
        $this->seed(QaBoardSeeder::class);
        $this->assertDatabaseCount('qa_threads', 12);
        $this->assertDatabaseCount('qa_replies', 12);
        $this->assertSame(12, QaThread::query()->where('user_id', $student->id)->count());
        $this->assertSame(4, QaThread::query()->where('status', QaThreadStatus::Resolved)->count());
        $this->assertSame(6, QaThread::query()->doesntHave('replies')->count());

        $thread = QaThread::query()->firstOrFail();
        $thread->update(['body' => '操作で変更した質問']);
        $reply = QaReply::query()->firstOrFail();
        $reply->update(['body' => '操作で変更した回答']);
        $this->seed(QaBoardSeeder::class);
        $this->assertDatabaseCount('qa_threads', 12);
        $this->assertDatabaseCount('qa_replies', 12);
        $this->assertSame('操作で変更した質問', $thread->refresh()->body);
        $this->assertSame('操作で変更した回答', $reply->refresh()->body);
    }
}
