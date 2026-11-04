<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\GeminiGateway;
use App\Enums\AiChatMessageRole;
use App\Enums\AiChatMessageStatus;
use App\Exceptions\AiChatException;
use App\Models\AiChatConversation;
use App\Models\AiChatDailyUsage;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AiChatMessageService
{
    public function __construct(private readonly GeminiGateway $gateway, private readonly AiChatContextService $context) {}

    public function send(User $user, AiChatConversation $conversation, string $content): array
    {
        abort_unless(config('ai-chat.enabled'), 404);
        abort_if(blank(config('ai-chat.gemini.api_key')), 503, 'AI相談は現在利用できません。接続設定が準備されるまでお待ちください。');
        [$conversation, $question, $answer] = $this->reserve($user, $conversation, $content);
        $error = null;
        $response = null;
        $title = null;
        $titleMetadata = null;
        $started = hrtime(true);
        try {
            $history = $conversation->messages()->reorder()->where('sequence', '<=', $question->sequence)
                ->orderByDesc('sequence')->limit(20)->get()->reverse()
                ->filter(fn ($message) => $message->status === AiChatMessageStatus::Completed)->values();
            while ($history->isNotEmpty() && $history->first()->role === AiChatMessageRole::Assistant) {
                $history->shift();
            }
            $contents = [];
            foreach ($history as $message) {
                $role = $message->role === AiChatMessageRole::User ? 'user' : 'model';
                $last = array_key_last($contents);
                if ($last !== null && $contents[$last]['role'] === $role) {
                    $contents[$last]['parts'][] = ['text' => $message->content];
                } else {
                    $contents[] = ['role' => $role, 'parts' => [['text' => $message->content]]];
                }
            }
            $response = $this->gateway->generate($contents, $this->context->prompt($conversation));
            if (trim($response->content) === '') {
                throw new AiChatException('empty_response');
            }
            $titleState = $conversation->fresh();
            if ($titleState?->auto_title && ($titleState->title_question_count === 0 || $conversation->question_count - $titleState->title_question_count >= 5)) {
                try {
                    $titleResponse = $this->gateway->generate($contents, '会話の学習内容を表すタイトルだけを日本語50文字以内で返してください。記号や説明は不要です。');
                    $title = mb_substr(trim(preg_replace('/\s+/u', ' ', $titleResponse->content)), 0, 50);
                    if ($title === '') {
                        throw new AiChatException('empty_response');
                    }
                    $titleMetadata = $titleResponse->metadata() + ['status' => 'completed'];
                } catch (Throwable $titleError) {
                    $title = null;
                    $titleMetadata = ['status' => 'error', 'error_code' => $titleError instanceof AiChatException ? $titleError->reason : 'internal_error'];
                }
            }
        } catch (Throwable $failure) {
            $error = $failure instanceof AiChatException ? $failure : new AiChatException('internal_error');
        }

        $result = DB::transaction(function () use ($user, $conversation, $question, $answer, $response, $error, $title, $titleMetadata, $started) {
            $owner = User::query()->lockForUpdate()->findOrFail($user->id);
            $current = AiChatConversation::query()->lockForUpdate()->findOrFail($conversation->id);
            Gate::forUser($owner)->authorize('update', $current);
            $answer->refresh()->fill($response?->metadata() ?? ['model' => config('ai-chat.gemini.model')]);
            $answer->fill(['status' => $error ? AiChatMessageStatus::Error : AiChatMessageStatus::Completed,
                'content' => $error ? $error->getMessage() : $response->content,
                'error_code' => $error?->reason, 'upstream_status' => $error?->upstreamStatus,
                'response_time_ms' => $response?->responseTimeMs ?? (int) round((hrtime(true) - $started) / 1000000)])->save();
            if ($current->auto_title && $current->title_question_count === $conversation->title_question_count && $titleMetadata !== null) {
                $current->title_metadata = $titleMetadata;
                if ($title !== null) {
                    $current->title = $title;
                    $current->title_question_count = $conversation->question_count;
                }
            }
            $current->last_message_at = now();
            $current->save();

            return ['conversation' => $current, 'user_message' => $question, 'assistant_message' => $answer, 'status' => $error ? 502 : 200, 'upstream_status' => $error?->upstreamStatus];
        });
        if ($error) {
            try {
                Log::channel('ai-chat')->warning('AI response failed', ['conversation_id' => $conversation->id, 'message_id' => $answer->id, 'error_code' => $error->reason, 'upstream_status' => $error->upstreamStatus]);
            } catch (Throwable) {
                // Persisted error metadata remains available even if the log destination is unavailable.
            }
        }

        return $result;
    }

    private function reserve(User $user, AiChatConversation $conversation, string $content): array
    {
        return DB::transaction(function () use ($user, $conversation, $content) {
            // Existing user rows serialize quota creation, including the first send of a JST day.
            $owner = User::query()->lockForUpdate()->findOrFail($user->id);
            $current = AiChatConversation::query()->lockForUpdate()->findOrFail($conversation->id);
            Gate::forUser($owner)->authorize('update', $current);
            $pending = $current->messages()->where('status', AiChatMessageStatus::Pending);
            $expiry = now()->subSeconds(max(1, (int) config('ai-chat.gemini.timeout', 30)) * 2 + 30);
            abort_if((clone $pending)->where('created_at', '>', $expiry)->exists(), 409, '回答を待っています。完了してから送信してください。');
            $pending->update(['status' => AiChatMessageStatus::Error, 'error_code' => 'interrupted', 'content' => '応答を完了できませんでした。同じ内容を再送できます。']);
            $day = Carbon::now('Asia/Tokyo');
            $usage = AiChatDailyUsage::firstOrCreate(['user_id' => $owner->id, 'usage_date' => $day->toDateString()]);
            abort_if($usage->attempts >= max(0, (int) config('ai-chat.daily_limit', 30)), 429, '本日の利用上限に達しました。日本時間の翌日0:00以降に再度ご利用ください。');
            $usage->increment('attempts');
            $sequence = (int) $current->messages()->max('sequence');
            $question = $current->messages()->create(['sequence' => $sequence + 1, 'role' => AiChatMessageRole::User, 'status' => AiChatMessageStatus::Completed, 'content' => $content]);
            $answer = $current->messages()->create(['sequence' => $sequence + 2, 'reply_to_id' => $question->id, 'role' => AiChatMessageRole::Assistant, 'status' => AiChatMessageStatus::Pending, 'content' => '']);
            $current->increment('question_count');
            $current->update(['last_message_at' => now()]);

            // Commit both the question and the quota before any external request.
            return [$current, $question, $answer];
        });
    }
}
