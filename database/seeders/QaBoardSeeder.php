<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\QaThreadStatus;
use App\Enums\UserRole;
use App\Models\Certification;
use App\Models\QaThread;
use App\Models\User;
use Illuminate\Database\Seeder;

final class QaBoardSeeder extends Seeder
{
    public function run(): void
    {
        $student = User::query()->where('email', 'student@certify-lms.test')->where('role', UserRole::Student)->first();

        if ($student === null) {
            $this->command?->warn('QaBoardSeeder: 固定受講生が存在しません。先に UserSeeder を実行してください。');

            return;
        }

        foreach (Certification::query()->published()->with('coaches')->get() as $certification) {
            for ($number = 1; $number <= 6; $number++) {
                $date = now()->subDays($number)->subMinutes($number * 7);
                $resolved = $number % 3 === 0;
                $thread = QaThread::firstOrCreate([
                    'certification_id' => $certification->id,
                    'user_id' => $student->id,
                    'title' => $certification->name.'の学習相談 '.$number,
                ], [
                    'body' => '学習を進める中で、基礎知識の整理と復習の方法について質問があります。具体例を交えて教えてください。',
                    'status' => $resolved ? QaThreadStatus::Resolved : QaThreadStatus::Open,
                    'resolved_at' => $resolved ? $date->copy()->addHours(2) : null,
                    'created_at' => $date,
                    'updated_at' => $date,
                ]);

                // 再実行で、操作済みの質問や回答を上書きしない。
                if (! $thread->wasRecentlyCreated || $number % 2 !== 0) {
                    continue;
                }

                $responders = [$certification->coaches->first() ?? $student, $student];
                foreach ($responders as $index => $responder) {
                    $thread->replies()->create([
                        'user_id' => $responder->id,
                        'body' => $index === 0 ? 'まずは基本用語と具体例を対応させて整理してみてください。' : 'ありがとうございます。復習の際に試してみます。',
                        'created_at' => $date->copy()->addMinutes(($index + 1) * 30),
                        'updated_at' => $date->copy()->addMinutes(($index + 1) * 30),
                    ]);
                }
            }
        }
    }
}
