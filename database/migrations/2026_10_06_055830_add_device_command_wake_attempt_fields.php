<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_commands', function (Blueprint $table): void {
            $table->timestamp('wake_sent_at')->nullable()->after('offered_at');
            $table->string('wake_error', 512)->nullable()->after('wake_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('device_commands', function (Blueprint $table): void {
            $table->dropColumn(['wake_sent_at', 'wake_error']);
        });
    }
};
