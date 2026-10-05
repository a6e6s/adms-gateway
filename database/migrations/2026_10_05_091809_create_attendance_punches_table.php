<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_punches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->foreignId('attendance_upload_id')->constrained()->restrictOnDelete();
            $table->foreignId('device_employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('pin');
            $table->string('occurred_at_local');
            $table->timestamp('occurred_at_utc')->nullable()->index();
            $table->string('timezone')->default('UTC');
            $table->string('time_quality')->default('unresolved');
            $table->string('status_code')->nullable();
            $table->string('verification_code')->nullable();
            $table->string('work_code')->nullable();
            $table->json('raw_fields');
            $table->char('deduplication_hash', 64);
            $table->unsignedSmallInteger('identity_version')->default(1);
            $table->timestamp('received_at')->index();
            $table->timestamps();
            $table->unique(['device_id', 'deduplication_hash']);
            $table->index(['company_id', 'occurred_at_local']);
            $table->index(['device_id', 'pin', 'occurred_at_local']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_punches');
    }
};
