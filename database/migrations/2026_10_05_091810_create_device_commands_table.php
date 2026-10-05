<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('device_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type')->default('request_attendance');
            $table->unsignedInteger('wire_command_id');
            $table->text('wire_payload');
            $table->string('status')->default('pending')->index();
            $table->timestamp('requested_at')->index();
            $table->timestamp('offered_at')->nullable()->index();
            $table->timestamp('result_received_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->string('result_source_ip', 45)->nullable();
            $table->longText('raw_result')->nullable();
            $table->json('result_history')->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'wire_command_id']);
            $table->index(['device_id', 'status', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('device_commands');
    }
};
