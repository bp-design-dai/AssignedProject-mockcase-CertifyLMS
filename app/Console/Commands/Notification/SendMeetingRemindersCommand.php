<?php

declare(strict_types=1);

namespace App\Console\Commands\Notification;

use App\Enums\MeetingReminderWindow;
use App\UseCases\Notification\SendMeetingRemindersAction;
use Illuminate\Console\Command;

class SendMeetingRemindersCommand extends Command
{
    protected $signature = 'notifications:send-meeting-reminders {--window= : eve または one_hour_before}';

    protected $description = '予約済み面談の前日・1時間前リマインダーを同期配信する';

    public function handle(SendMeetingRemindersAction $action): int
    {
        $window = MeetingReminderWindow::tryFrom((string) $this->option('window'));
        if (! $window) {
            $this->error('--window は eve または one_hour_before を指定してください。');

            return self::INVALID;
        }
        $result = $action($window);
        $this->info('対象面談'.$result['meetings'].'件、送信'.$result['sent'].'件、失敗'.$result['failed'].'件（通知方法ごと）');

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
