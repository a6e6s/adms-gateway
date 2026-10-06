<?php

namespace App\Http\Resources\BioTime;

use App\Models\AttendancePunch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AttendancePunch */
class TransactionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $metadata = $this->biotime_metadata ?? [];

        return [
            'id' => $this->id,
            'emp' => null,
            'emp_code' => $this->employee->employee_number ?? $this->pin,
            'first_name' => null,
            'last_name' => null,
            'department' => null,
            'position' => null,
            'punch_time' => $this->occurred_at_local,
            'punch_state' => $this->status_code,
            'punch_state_display' => match ($this->status_code) {
                '0' => 'Check In',
                '1' => 'Check Out',
                '4' => 'Overtime In',
                '5' => 'Overtime Out',
                default => 'Unknown',
            },
            'verify_type' => $this->verification_code !== null && ctype_digit($this->verification_code)
                ? (int) $this->verification_code : null,
            'verify_type_display' => match ($this->verification_code) {
                '1' => 'Fingerprint',
                '3' => 'Password',
                '4' => 'Card',
                '15' => 'Face',
                default => 'Unknown',
            },
            'work_code' => $this->work_code ?? '',
            'gps_location' => $metadata['gps_location'] ?? null,
            'area_alias' => $metadata['area_alias'] ?? $this->device->location ?? '',
            'terminal_sn' => $this->device->serial_number,
            'temperature' => $metadata['temperature'] ?? 0,
            'is_mask' => $metadata['is_mask'] ?? '-',
            'terminal_alias' => $this->device->name,
            'upload_time' => $this->received_at->setTimezone($this->timezone)->format('Y-m-d H:i:s'),
        ];
    }
}
