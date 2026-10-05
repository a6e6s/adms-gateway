<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_uploads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->longText('raw_payload');
            $table->char('payload_sha256', 64);
            $table->unsignedInteger('byte_count');
            $table->string('source_stamp')->nullable();
            $table->timestamp('received_at')->index();
            $table->string('source_ip', 45)->nullable();
            $table->string('device_timezone')->default('UTC');
            $table->string('parser_version')->default('push-2.4-attlog-v1');
            $table->string('status')->default('pending')->index();
            $table->unsignedSmallInteger('processing_attempts')->default(0);
            $table->string('processing_token')->nullable();
            $table->timestamp('processing_lease_expires_at')->nullable()->index();
            $table->timestamp('last_dispatched_at')->nullable();
            $table->unsignedInteger('checkpoint_line')->default(0);
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('inserted_rows')->default(0);
            $table->unsignedInteger('duplicate_rows')->default(0);
            $table->unsignedInteger('rejected_rows')->default(0);
            $table->json('errors')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'last_dispatched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_uploads');
    }
};
