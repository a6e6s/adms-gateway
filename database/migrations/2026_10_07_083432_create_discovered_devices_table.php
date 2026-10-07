<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discovered_devices', function (Blueprint $table) {
            $table->id();
            $table->string('serial_number', 100)->unique();
            $table->string('last_seen_ip', 45)->nullable();
            $table->string('push_version', 40)->nullable();
            $table->string('device_type', 80)->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at')->index();
            $table->unsignedBigInteger('attempt_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discovered_devices');
    }
};
