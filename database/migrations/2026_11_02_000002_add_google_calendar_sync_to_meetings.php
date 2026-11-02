<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            // Deliberately not a FK: retain the old connection generation after disconnect.
            $table->ulid('google_calendar_credential_id')->nullable();
            $table->string('google_calendar_event_id', 64)->nullable();
            $table->timestamp('google_calendar_created_at')->nullable();
            $table->timestamp('google_calendar_deleted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('meetings', fn (Blueprint $table) => $table->dropColumn([
            'google_calendar_credential_id', 'google_calendar_event_id', 'google_calendar_created_at', 'google_calendar_deleted_at',
        ]));
    }
};
