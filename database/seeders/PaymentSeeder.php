<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\MeetingQuotaTransactionType;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\MeetingPack;
use App\Models\MeetingQuotaTransaction;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PaymentSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing']) || ! Schema::hasTable('payments')) {
            return;
        }
        $pack = MeetingPack::published()->where('price', '>', 0)->ordered()->first();
        if (! $pack) {
            return;
        }
        $users = User::where('role', UserRole::Student)->where('status', UserStatus::InProgress)
            ->orderByRaw('CASE WHEN email = ? THEN 0 ELSE 1 END', ['student@certify-lms.test'])->orderBy('id')->limit(3)->get();
        foreach ($users as $user) {
            DB::transaction(function () use ($user, $pack) {
                User::lockForUpdate()->findOrFail($user->id);
                foreach (PaymentStatus::cases() as $status) {
                    $id = substr('01'.hash('sha256', 'sa03-demo:'.$user->id.':'.$status->value), 0, 26);
                    $payment = Payment::firstOrCreate(['id' => $id], [
                        'user_id' => $user->id, 'meeting_pack_id' => $pack->id, 'pack_name' => $pack->name,
                        'amount' => $pack->price, 'quantity' => $pack->meeting_count, 'currency' => 'jpy', 'status' => $status,
                        'stripe_session_id' => 'cs_test_demo_'.$id,
                        'stripe_payment_intent_id' => $status === PaymentStatus::Succeeded ? 'pi_demo_'.$id : null,
                        'fulfilled_at' => $status === PaymentStatus::Succeeded ? now() : null,
                    ]);
                    if ($payment->status === PaymentStatus::Succeeded) {
                        MeetingQuotaTransaction::firstOrCreate(['related_payment_id' => $payment->id], [
                            'user_id' => $user->id, 'type' => MeetingQuotaTransactionType::Purchased,
                            'amount' => $payment->quantity, 'note' => '購入履歴デモ（実決済なし）', 'occurred_at' => $payment->fulfilled_at,
                        ]);
                    }
                }
            });
        }
    }
}
