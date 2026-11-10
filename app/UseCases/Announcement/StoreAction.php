<?php

declare(strict_types=1);

namespace App\UseCases\Announcement;

use App\Jobs\PrepareAnnouncementDelivery;
use App\Models\Announcement;
use App\Models\AnnouncementDispatch;
use App\Models\User;
use App\Services\AnnouncementRecipientService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class StoreAction
{
    public function __construct(private AnnouncementRecipientService $recipients) {}

    public function __invoke(User $user, array $data): AnnouncementDispatch
    {
        Gate::forUser($user)->authorize('create', Announcement::class);
        $payload = array_intersect_key($data, array_flip(['title', 'body', 'target_type', 'target_certification_id', 'target_user_id']));
        ksort($payload);
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $dispatch = DB::transaction(function () use ($data, $user, $payload, $hash) {
            $dispatch = AnnouncementDispatch::firstOrCreate(['idempotency_key' => $data['idempotency_key']], [
                'created_by_user_id' => $user->id, 'payload_hash' => $hash, 'payload' => $payload,
            ]);
            if (! $dispatch->wasRecentlyCreated) {
                abort_unless($dispatch->created_by_user_id === $user->id && hash_equals($dispatch->payload_hash, $hash), 409);
                abort_unless($dispatch->completed_at && Announcement::whereKey($dispatch->id)->exists(), 409);

                return $dispatch;
            }
            if (! $this->recipients->query($data)->exists()) {
                throw ValidationException::withMessages(['target_type' => '配信対象の受講生がいません。']);
            }
            DB::afterCommit(fn () => PrepareAnnouncementDelivery::dispatch($dispatch->id));

            return $dispatch;
        });

        return $dispatch->refresh();
    }
}
