<?php

namespace Database\Factories;

use App\Models\AttendancePunch;
use App\Models\AttendanceUpload;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendancePunch>
 */
class AttendancePunchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'attendance_upload_id' => AttendanceUpload::factory(),
            'device_id' => fn (array $attributes): int => AttendanceUpload::query()->findOrFail($attributes['attendance_upload_id'])->device_id,
            'company_id' => fn (array $attributes): int => AttendanceUpload::query()->findOrFail($attributes['attendance_upload_id'])->company_id,
            'pin' => (string) fake()->numberBetween(1, 99999),
            'occurred_at_local' => '2026-10-05 08:00:00',
            'occurred_at_utc' => now(),
            'timezone' => 'UTC',
            'time_quality' => 'resolved',
            'status_code' => '0',
            'verification_code' => '1',
            'raw_fields' => ['10001', '2026-10-05 08:00:00', '0', '1', '', '0', '0', ''],
            'deduplication_hash' => hash('sha256', fake()->unique()->uuid()),
            'identity_version' => 1,
            'received_at' => now(),
        ];
    }
}
