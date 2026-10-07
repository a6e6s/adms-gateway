<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DeviceCommandFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property CarbonImmutable|null $expires_at */
class DeviceCommand extends Model
{
    /** @use HasFactory<DeviceCommandFactory> */
    use HasFactory;

    protected $fillable = ['device_id', 'requested_by', 'type', 'wire_command_id', 'wire_payload', 'query_start_time', 'query_end_time', 'status', 'requested_at', 'offered_at', 'wake_sent_at', 'wake_error', 'result_received_at', 'expires_at', 'result_source_ip', 'raw_result', 'result_history'];

    protected function casts(): array
    {
        return ['requested_at' => 'immutable_datetime', 'offered_at' => 'immutable_datetime', 'wake_sent_at' => 'immutable_datetime', 'result_received_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'result_history' => 'array'];
    }

    /** @return BelongsTo<Device, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return HasMany<AttendanceUpload, $this> */
    public function attendanceUploads(): HasMany
    {
        return $this->hasMany(AttendanceUpload::class);
    }
}
