<?php

declare(strict_types=1);

namespace App\UseCases\Notification;

use App\Enums\MeetingReminderWindow;
use App\Services\MeetingReminderDeliveryService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

final class SendMeetingRemindersAction
{
    public function __construct(private MeetingReminderDeliveryService $delivery) {}

    public function __invoke(MeetingReminderWindow $window): array
    {
        $now = CarbonImmutable::now(config('app.timezone'));
        $result = ['meetings' => 0, 'sent' => 0, 'failed' => 0];
        foreach ($this->delivery->targets($window, $now)->lazyById(100) as $meeting) {
            $result['meetings']++;
            foreach ([$meeting->student_id, $meeting->coach_id] as $userId) {
                foreach (['database', 'mail'] as $channel) {
                    try {
                        if ($this->delivery->send($meeting, $userId, $window, $channel, $now)) {
                            $result['sent']++;
                        }
                    } catch (\Throwable $exception) {
                        $result['failed']++;
                        try {
                            Log::warning('Meeting reminder delivery failed', ['window' => $window->value, 'channel' => $channel, 'exception_class' => $exception::class]);
                        } catch (\Throwable) {
                            // ログ障害があっても他の通知方法・受信者・面談へ継続する。
                        }
                    }
                }
            }
        }

        return $result;
    }
}
