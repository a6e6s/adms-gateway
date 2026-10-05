<?php

namespace Database\Factories;

use App\Models\AttendanceUpload;
use App\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceUpload>
 */
class AttendanceUploadFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'company_id' => fn (array $attributes): int => Device::query()->findOrFail($attributes['device_id'])->company_id,
            'raw_payload' => "10001\t2026-10-05 08:00:00\t0\t1\t\t0\t0\t\n",
            'payload_sha256' => hash('sha256', "10001\t2026-10-05 08:00:00\t0\t1\t\t0\t0\t\n"),
            'byte_count' => strlen("10001\t2026-10-05 08:00:00\t0\t1\t\t0\t0\t\n"),
            'source_stamp' => '9999',
            'received_at' => now(),
            'device_timezone' => 'UTC',
            'parser_version' => 'push-2.4-attlog-v1',
            'status' => 'pending',
        ];
    }
}
