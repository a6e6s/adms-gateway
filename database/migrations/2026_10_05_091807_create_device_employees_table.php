<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_employees', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('pin');
            $table->timestamps();
            $table->unique(['device_id', 'pin']);
            $table->unique(['device_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_employees');
    }
};
