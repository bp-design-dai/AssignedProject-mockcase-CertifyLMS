<?php

declare(strict_types=1);

namespace App\UseCases\Announcement;

use App\Models\Announcement;
use App\Models\AnnouncementDispatch;
use App\Models\User;
use App\Services\AnnouncementDeliveryService;
use App\Services\AnnouncementRecipientService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class StoreAction
{
    public function __construct(private AnnouncementRecipientService $recipients, private AnnouncementDeliveryService $delivery) {}

    public function __invoke(User $user, array $data): Announcement
    {
        Gate::forUser($user)->authorize('create', Announcement::class);
        $payload = array_intersect_key($data, array_flip(['title', 'body', 'target_type', 'target_certification_id', 'target_user_id']));
        ksort($payload);
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $dispatch = AnnouncementDispatch::query()->firstOrCreate(['idempotency_key' => $data['idempotency_key']], ['created_by_user_id' => $user->id, 'payload_hash' => $hash]);

        if (! $dispatch->wasRecentlyCreated) {
            abort_unless($dispatch->created_by_user_id === $user->id && hash_equals($dispatch->payload_hash, $hash), 409);
            // 実行中・失敗済みも同じ受付キーでは再送しない。再操作は新しい作成画面から行う。
            abort_unless($dispatch->completed_at && ($announcement = Announcement::find($dispatch->id)), 409);

            return $announcement;
        }

        $success = 0;
        $failed = 0;
        foreach ($this->recipients->query($data)->lazyById(100) as $recipient) {
            try {
                $this->delivery->send($recipient, $dispatch->id, $data['title'], $data['body']);
                $success++;
            } catch (\Throwable $exception) {
                $failed++;
                try {
                    Log::warning('Announcement delivery failed', ['dispatch_id' => $dispatch->id, 'exception_class' => $exception::class]);
                } catch (\Throwable) {
                    // ログ障害によって残りの受信者への配信を中断しない。
                }
            }
        }

        if ($success === 0) {
            $dispatch->update(['completed_at' => now()]);
            throw ValidationException::withMessages(['target_type' => $failed === 0 ? '配信対象の受講生がいません。' : 'すべてのお知らせ配信に失敗しました。新しい配信画面から再操作してください。']);
        }

        return DB::transaction(function () use ($payload, $dispatch, $user, $success, $failed) {
            $announcement = Announcement::create(array_merge($payload, ['id' => $dispatch->id, 'created_by_user_id' => $user->id, 'dispatched_count' => $success, 'failed_count' => $failed, 'dispatched_at' => now()]));
            $dispatch->update(['completed_at' => now()]);

            return $announcement;
        });
    }
}
