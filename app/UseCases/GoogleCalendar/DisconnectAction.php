<?php

declare(strict_types=1);

namespace App\UseCases\GoogleCalendar;

use App\Enums\UserRole;
use App\Models\GoogleCalendarCredential;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;

final class DisconnectAction
{
    public function __invoke(User $coach, Session $session): void
    {
        abort_unless($coach->role === UserRole::Coach, 403);
        DB::transaction(function () use ($coach) {
            User::whereKey($coach->id)->lockForUpdate()->firstOrFail();
            GoogleCalendarCredential::where('user_id', $coach->id)->delete();
        });
        $session->forget('google_calendar_oauth');
    }
}
