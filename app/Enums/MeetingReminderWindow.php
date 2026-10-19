<?php

declare(strict_types=1);

namespace App\Enums;

use Carbon\CarbonImmutable;

enum MeetingReminderWindow: string
{
    case Eve = 'eve';
    case OneHourBefore = 'one_hour_before';

    public function label(): string
    {
        return match ($this) {
            self::Eve => '前日',
            self::OneHourBefore => '1時間前',
        };
    }

    public function range(CarbonImmutable $now): array
    {
        return match ($this) {
            self::Eve => [$now->addDay()->startOfDay(), $now->addDay()->endOfDay()],
            self::OneHourBefore => [$now->addMinutes(55), $now->addMinutes(65)],
        };
    }
}
