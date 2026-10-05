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
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('serial_number')->unique();
            $table->string('name');
            $table->string('location')->nullable();
            $table->string('expected_ip', 45)->nullable();
            $table->string('timezone')->default('UTC');
            $table->string('protocol_profile')->default('push-2.4-attlog-v1');
            $table->string('push_version')->nullable();
            $table->string('device_type')->nullable();
            $table->boolean('is_enabled')->default(true)->index();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->string('last_seen_ip', 45)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'is_enabled']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
