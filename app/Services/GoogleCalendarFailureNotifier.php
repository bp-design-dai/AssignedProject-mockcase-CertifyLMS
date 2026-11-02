<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserRole;
use App\Models\GoogleCalendarCredential;
use App\Models\User;
use App\Notifications\GoogleCalendarFailure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

final class GoogleCalendarFailureNotifier
{
    public function notify(GoogleCalendarCredential $credential, string $operation, ?string $meetingId = null): void
    {
        DB::afterCommit(function () use ($credential, $operation, $meetingId) {
            try {
                $coach = User::find($credential->user_id);
                if ($coach?->role !== UserRole::Coach) {
                    return;
                }
                $message = match ($operation) {
                    'authentication' => 'Googleとの認証を更新できませんでした。面談設定でGoogleカレンダーを再連携してください。',
                    'refresh' => 'Googleとの認証更新に失敗しました。接続状況を確認し、改善しない場合は面談設定で再連携してください。',
                    'create' => '面談はLMSで予約済みですが、Google予定の登録に失敗しました。面談一覧とGoogleカレンダーを確認してください。',
                    default => '面談はLMSでキャンセル済みですが、Google予定の削除に失敗しました。Googleカレンダーで確認してください。',
                };
                $notification = new GoogleCalendarFailure($message);
                $coach->notifications()->firstOrCreate([
                    'id' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'certify-lms:google-calendar:'.$credential->id.':'.$operation.':'.$meetingId)->toString(),
                ], ['type' => GoogleCalendarFailure::class, 'data' => $notification->toDatabase($coach), 'read_at' => null]);
            } catch (\Throwable $exception) {
                Log::warning('Google Calendar failure notification failed', ['exception_class' => $exception::class]);
            }
        });
    }
}
