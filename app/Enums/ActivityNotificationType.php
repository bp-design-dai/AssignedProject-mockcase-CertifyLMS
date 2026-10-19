<?php

declare(strict_types=1);

namespace App\Enums;

enum ActivityNotificationType: string
{
    case ChatMessageReceived = 'chat_message_received';
    case QaReplyReceived = 'qa_reply_received';
    case MeetingReserved = 'meeting_reserved';
    case MeetingCanceled = 'meeting_canceled';
    case MeetingReminder = 'meeting_reminder';

    public function title(): string
    {
        return match ($this) {
            self::ChatMessageReceived => 'チャットに新しいメッセージが届きました',
            self::QaReplyReceived => '質問に回答が届きました',
            self::MeetingReserved => '面談が予約されました',
            self::MeetingCanceled => '面談がキャンセルされました',
            self::MeetingReminder => '面談のリマインダー',
        };
    }
}
