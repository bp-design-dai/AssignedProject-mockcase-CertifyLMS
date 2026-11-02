<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\GoogleCalendarCredential;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Isolated mock-test fixtures; never attach dummy Google tokens to existing demo users. */
final class GoogleCalendarDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('testing') || ! preg_match('/^testing(?:_\w+)?$/', DB::connection()->getDatabaseName())) {
            throw new \LogicException('Google Calendar fixtures require an isolated testing database.');
        }
        $linked = User::factory()->coach()->create(['name' => 'S-A-01 連携済み検証コーチ']);
        GoogleCalendarCredential::factory()->create(['user_id' => $linked->id]);
        User::factory()->coach()->create(['name' => 'S-A-01 未連携検証コーチ']);
    }
}
