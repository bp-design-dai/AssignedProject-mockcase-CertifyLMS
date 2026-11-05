<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('meeting_pack_id')->constrained()->restrictOnDelete();
            $table->string('pack_name');
            $table->unsignedInteger('amount');
            $table->unsignedInteger('quantity');
            $table->char('currency', 3)->default('jpy');
            $table->string('status', 30)->default('pending');
            $table->string('stripe_session_id')->nullable()->unique();
            $table->string('stripe_payment_intent_id')->nullable()->unique();
            $table->string('failure_code', 40)->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
        Schema::create('stripe_webhook_events', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignUlid('payment_id')->constrained()->restrictOnDelete();
            $table->string('type');
            $table->timestamp('processed_at');
        });
        Schema::table('meeting_quota_transactions', function (Blueprint $table) {
            $table->unique('related_payment_id', 'meeting_quota_payment_unique');
            $table->foreign('related_payment_id', 'meeting_quota_payment_foreign')->references('id')->on('payments')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('meeting_quota_transactions', function (Blueprint $table) {
            $table->dropForeign('meeting_quota_payment_foreign');
            $table->dropUnique('meeting_quota_payment_unique');
        });
        Schema::dropIfExists('stripe_webhook_events');
        Schema::dropIfExists('payments');
    }
};
