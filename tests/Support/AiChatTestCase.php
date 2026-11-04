<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\GeminiGateway;
use App\Data\GeminiResponse;
use App\Models\AiChatConversation;
use App\Models\Certification;
use App\Models\Chapter;
use App\Models\Enrollment;
use App\Models\Part;
use App\Models\Section;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

abstract class AiChatTestCase extends TestCase
{
    use RefreshDatabase;

    protected $gateway;

    protected function beforeRefreshingDatabase(): void
    {
        if (! app()->environment('testing') || ! preg_match('/^testing(?:_\w+)?$/', DB::connection()->getDatabaseName())) {
            throw new \RuntimeException('Only an isolated testing database may be migrated.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2030-06-10 18:00:00', 'Asia/Tokyo'));
        config(['ai-chat.enabled' => true, 'ai-chat.gemini.api_key' => 'sa02-fake-key-never-real', 'ai-chat.gemini.model' => 'test-config-model', 'ai-chat.daily_limit' => 30]);
        Http::preventStrayRequests();
        $this->gateway = $this->mock(GeminiGateway::class);
        $this->gateway->shouldReceive('generate')->andReturn(new GeminiResponse('学習を少しずつ進めましょう。', 'internal-test-model', 101, 37, 138, 25))->byDefault();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function learner(): User
    {
        return User::factory()->student()->inProgress()->create();
    }

    protected function conversation(?User $user = null, array $attributes = []): AiChatConversation
    {
        return AiChatConversation::factory()->create(['user_id' => ($user ?? $this->learner())->id] + $attributes);
    }

    protected function context(?User $user = null): array
    {
        $user ??= $this->learner();
        $certification = Certification::factory()->published()->create();
        $enrollment = Enrollment::factory()->create(['user_id' => $user->id, 'certification_id' => $certification->id]);
        $part = Part::factory()->published()->create(['certification_id' => $certification->id]);
        $chapter = Chapter::factory()->published()->create(['part_id' => $part->id]);
        $section = Section::factory()->published()->create(['chapter_id' => $chapter->id, 'body' => '教材専用の二分探索に関する説明。']);

        return [$user, $enrollment, $section];
    }

    protected function send(AiChatConversation $conversation, string $text = '学習方法を教えてください。')
    {
        return $this->actingAs($conversation->user)->postJson(route('ai-chat.conversations.messages.store', $conversation), ['content' => $text]);
    }
}
