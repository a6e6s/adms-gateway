<?php

namespace App\Models;

use Database\Factories\DeviceCommandFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeviceCommand extends Model
{
    /** @use HasFactory<DeviceCommandFactory> */
    use HasFactory;

    protected $fillable = ['device_id', 'requested_by', 'type', 'wire_command_id', 'wire_payload', 'query_start_time', 'query_end_time', 'status', 'requested_at', 'offered_at', 'wake_sent_at', 'wake_error', 'result_received_at', 'expires_at', 'result_source_ip', 'raw_result', 'result_history'];

    protected function casts(): array
    {
        return ['requested_at' => 'immutable_datetime', 'offered_at' => 'immutable_datetime', 'wake_sent_at' => 'immutable_datetime', 'result_received_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'result_history' => 'array'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function attendanceUploads(): HasMany
    {
        return $this->hasMany(AttendanceUpload::class);
    }
}
