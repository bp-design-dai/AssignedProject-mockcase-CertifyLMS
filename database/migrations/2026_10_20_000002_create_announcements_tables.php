<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 配信履歴ではなく、二重POSTを防ぐための内部受付記録。本文は保存しない。
        Schema::create('announcement_dispatches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->uuid('idempotency_key')->unique();
            $table->foreignUlid('created_by_user_id')->constrained('users');
            $table->char('payload_hash', 64);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('announcements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('title', 200);
            $table->text('body');
            $table->string('target_type');
            $table->foreignUlid('target_certification_id')->nullable()->constrained('certifications')->nullOnDelete();
            $table->foreignUlid('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('created_by_user_id')->constrained('users');
            $table->unsignedInteger('dispatched_count');
            $table->unsignedInteger('failed_count');
            $table->timestamp('dispatched_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('announcement_dispatches');
    }
};
