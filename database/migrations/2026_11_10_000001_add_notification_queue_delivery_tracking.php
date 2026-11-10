<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('delivery_key', 191);
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 16);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('skipped_at')->nullable();
            $table->string('exception_class')->nullable();
            $table->timestamps();
            $table->unique(['delivery_key', 'user_id', 'channel'], 'notification_delivery_unique');
        });
        Schema::table('meeting_reminder_deliveries', function (Blueprint $table) {
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('skipped_at')->nullable();
            $table->string('exception_class')->nullable();
        });
        Schema::table('announcement_dispatches', function (Blueprint $table) {
            $table->json('payload')->nullable();
            $table->string('status')->default('queued');
            $table->timestamp('prepared_at')->nullable();
            $table->unsignedInteger('target_count')->default(0);
            $table->unsignedInteger('succeeded_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->string('exception_class')->nullable();
        });
        Schema::create('announcement_recipients', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('announcement_dispatch_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->string('exception_class')->nullable();
            $table->timestamps();
            $table->unique(['announcement_dispatch_id', 'user_id'], 'announcement_recipient_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_recipients');
        Schema::table('announcement_dispatches', fn (Blueprint $table) => $table->dropColumn(['payload', 'status', 'prepared_at', 'target_count', 'succeeded_count', 'failed_count', 'exception_class']));
        Schema::table('meeting_reminder_deliveries', fn (Blueprint $table) => $table->dropColumn(['queued_at', 'failed_at', 'skipped_at', 'exception_class']));
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('jobs');
    }
};
