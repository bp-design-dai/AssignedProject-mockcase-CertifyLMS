<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Tests\TestCase;

class MeetingReminderScheduleTest extends TestCase
{
    public function test_reminder_commands_are_scheduled_at_1800_and_every_five_minutes_in_tokyo(): void
    {
        $this->app->make(Kernel::class)->bootstrap();
        $events = collect($this->app->make(Schedule::class)->events())
            ->filter(fn ($event) => str_contains($event->command ?? '', 'notifications:send-meeting-reminders'));
        $this->assertCount(2, $events);
        $eve = $events->first(fn ($event) => str_contains($event->command, '--window=eve'));
        $hour = $events->first(fn ($event) => str_contains($event->command, '--window=one_hour_before'));
        $this->assertSame('0 18 * * *', $eve->expression);
        $this->assertSame('*/5 * * * *', $hour->expression);
        foreach ([$eve, $hour] as $event) {
            $this->assertSame('Asia/Tokyo', $event->timezone);
            $this->assertTrue($event->withoutOverlapping);
        }
        try {
            foreach (['2030-06-10 17:59' => [false, false], '2030-06-10 18:00' => [true, true], '2030-06-10 18:01' => [false, false], '2030-06-10 18:05' => [false, true]] as $time => [$eveDue, $hourDue]) {
                Carbon::setTestNow(Carbon::parse($time, 'Asia/Tokyo'));
                $this->assertSame($eveDue, $eve->isDue($this->app));
                $this->assertSame($hourDue, $hour->isDue($this->app));
            }
        } finally {
            Carbon::setTestNow();
        }
    }
}
