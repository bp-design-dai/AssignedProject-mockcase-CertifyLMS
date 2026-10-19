<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AnnouncementTargetType;
use App\Models\Announcement;
use App\Models\User;
use App\Notifications\AnnouncementNotification;
use App\Services\AnnouncementRecipientService;
use Illuminate\Database\Seeder;
use Ramsey\Uuid\Uuid;

class AnnouncementSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('email', 'admin@certify-lms.test')->first();
        $student = app(AnnouncementRecipientService::class)->query()->where('email', 'student@certify-lms.test')->first();
        if (! $admin || ! $student) {
            return;
        }
        $certification = $student->enrollments()->first()?->certification;
        foreach (AnnouncementTargetType::cases() as $type) {
            if ($type === AnnouncementTargetType::Certification && ! $certification) {
                continue;
            }
            $data = ['target_type' => $type->value];
            if ($type === AnnouncementTargetType::Certification) {
                $data['target_certification_id'] = $certification->id;
            } elseif ($type === AnnouncementTargetType::User) {
                $data['target_user_id'] = $student->id;
            }
            $recipients = app(AnnouncementRecipientService::class)->query($data)->get();
            $announcement = Announcement::firstOrCreate(['title' => '【デモS-B-08】'.$type->label().'へのお知らせ'], array_merge($data, ['body' => "運営からのデモお知らせです。\n配信履歴と通知詳細の確認にご利用ください。", 'created_by_user_id' => $admin->id, 'dispatched_count' => $recipients->count(), 'failed_count' => 0, 'dispatched_at' => now()]));
            if (! $announcement->wasRecentlyCreated) {
                continue;
            }
            foreach ($recipients as $recipient) {
                $notification = new AnnouncementNotification($announcement->id, $announcement->title, $announcement->body);
                $notification->id = Uuid::uuid5(Uuid::NAMESPACE_URL, 'certify-lms:announcement:'.$announcement->id.':'.$recipient->id)->toString();
                $recipient->notifications()->firstOrCreate(['id' => $notification->id], ['type' => AnnouncementNotification::class, 'data' => $notification->toDatabase($recipient), 'read_at' => null]);
            }
        }
    }
}
