<?php

namespace App\Models;

use Database\Factories\AttendancePunchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class AttendancePunch extends Model
{
    /** @use HasFactory<AttendancePunchFactory> */
    use HasFactory;

    protected $fillable = ['company_id', 'device_id', 'attendance_upload_id', 'device_employee_id', 'pin', 'occurred_at_local', 'occurred_at_utc', 'timezone', 'time_quality', 'status_code', 'verification_code', 'work_code', 'raw_fields', 'deduplication_hash', 'identity_version', 'received_at'];

    protected function casts(): array
    {
        return ['occurred_at_utc' => 'immutable_datetime', 'received_at' => 'immutable_datetime', 'raw_fields' => 'array'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function upload(): BelongsTo
    {
        return $this->belongsTo(AttendanceUpload::class, 'attendance_upload_id');
    }

    public function deviceEmployee(): BelongsTo
    {
        return $this->belongsTo(DeviceEmployee::class);
    }

    public function employee(): HasOneThrough
    {
        return $this->hasOneThrough(Employee::class, DeviceEmployee::class, 'id', 'id', 'device_employee_id', 'employee_id');
    }
}
