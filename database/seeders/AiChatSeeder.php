<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Models\AiChatConversation;
use App\Models\Section;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

final class AiChatSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('ai_chat_conversations')) {
            return;
        }
        $user = User::where('email', 'student@certify-lms.test')->first();
        if (! $user) {
            return;
        }
        $enrollment = $user->enrollments()->learning()->whereHas('certification', fn ($q) => $q->published())->first();
        $section = $enrollment ? Section::published()->whereHas('chapter.part', fn ($q) => $q->where('certification_id', $enrollment->certification_id))->first() : null;
        foreach ([['学習計画の相談（デモ）', null, false], ['教材の相談（デモ）', $section, false], ['再送を確認する相談（デモ）', null, true]] as [$title, $material, $error]) {
            if ($material && $user->aiChatConversations()->where('section_id', $material->id)->exists()) {
                continue;
            }
            $id = substr('01'.hash('sha256', 'sa02-demo-'.$title), 0, 26);
            $conversation = AiChatConversation::firstOrCreate(['id' => $id], ['user_id' => $user->id, 'enrollment_id' => $enrollment?->id,
                'section_id' => $material?->id, 'title' => $title, 'question_count' => 1, 'title_question_count' => $error ? 0 : 1, 'last_message_at' => now()]);
            if (! $conversation->wasRecentlyCreated) {
                continue;
            }
            $question = $conversation->messages()->create(['sequence' => 1, 'role' => AiChatMessageRole::User, 'status' => AiChatMessageStatus::Completed, 'content' => '学習の進め方を相談したいです。']);
            $conversation->messages()->create(['sequence' => 2, 'reply_to_id' => $question->id, 'role' => AiChatMessageRole::Assistant,
                'status' => $error ? AiChatMessageStatus::Error : AiChatMessageStatus::Completed,
                'content' => $error ? 'AIが応答できませんでした。質問は保存しました。同じ内容を再送できます。' : '毎日の学習時間を決め、理解した内容を短く振り返ってみましょう。',
                'model' => 'demo-model', 'error_code' => $error ? 'transport_error' : null]);
        }
    }
}
