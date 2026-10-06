<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_commands', function (Blueprint $table): void {
            $table->string('query_start_time', 19)->nullable()->after('wire_payload');
            $table->string('query_end_time', 19)->nullable()->after('query_start_time');
        });

        Schema::table('attendance_uploads', function (Blueprint $table): void {
            $table->foreignId('device_command_id')->nullable()->after('device_id')->constrained()->nullOnDelete();
            $table->index(['device_command_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::table('attendance_uploads', function (Blueprint $table): void {
            $table->dropIndex(['device_command_id', 'received_at']);
            $table->dropConstrainedForeignId('device_command_id');
        });

        Schema::table('device_commands', function (Blueprint $table): void {
            $table->dropColumn(['query_start_time', 'query_end_time']);
        });
    }
};
