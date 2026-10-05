<?php

namespace App\Models;

use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
    use HasFactory;

    protected $fillable = ['company_id', 'serial_number', 'name', 'location', 'expected_ip', 'timezone', 'protocol_profile', 'push_version', 'device_type', 'is_enabled', 'last_seen_ip', 'attlog_stamp'];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'last_seen_at' => 'immutable_datetime', 'last_getrequest_at' => 'immutable_datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function attendanceUploads(): HasMany
    {
        return $this->hasMany(AttendanceUpload::class);
    }

    public function attendancePunches(): HasMany
    {
        return $this->hasMany(AttendancePunch::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(DeviceEmployee::class);
    }

    public function commands(): HasMany
    {
        return $this->hasMany(DeviceCommand::class);
    }
}
