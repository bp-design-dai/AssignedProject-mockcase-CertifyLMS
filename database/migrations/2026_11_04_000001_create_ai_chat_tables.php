<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_chat_conversations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('enrollment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('section_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 50)->default('新しい相談');
            $table->boolean('auto_title')->default(true);
            $table->unsignedInteger('question_count')->default(0);
            $table->unsignedInteger('title_question_count')->default(0);
            $table->json('title_metadata')->nullable();
            $table->json('public_context')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'section_id']);
            $table->index(['user_id', 'last_message_at']);
        });
        Schema::create('ai_chat_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('conversation_id')->constrained('ai_chat_conversations')->cascadeOnDelete();
            $table->foreignUlid('reply_to_id')->nullable()->constrained('ai_chat_messages')->nullOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('role', 20);
            $table->string('status', 20);
            $table->longText('content');
            $table->string('model')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->string('error_code', 40)->nullable();
            $table->unsignedSmallInteger('upstream_status')->nullable();
            $table->timestamps();
            $table->unique(['conversation_id', 'sequence']);
            $table->unique('reply_to_id');
        });
        Schema::create('ai_chat_daily_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->date('usage_date');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'usage_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chat_daily_usages');
        Schema::dropIfExists('ai_chat_messages');
        Schema::dropIfExists('ai_chat_conversations');
    }
};
