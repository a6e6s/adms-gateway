<?php

namespace App\Models;

use Database\Factories\AttendanceUploadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceUpload extends Model
{
    /** @use HasFactory<AttendanceUploadFactory> */
    use HasFactory;

    protected $fillable = ['company_id', 'device_id', 'device_command_id', 'raw_payload', 'payload_sha256', 'byte_count', 'source_stamp', 'received_at', 'source_ip', 'device_timezone', 'parser_version', 'status', 'processing_attempts', 'processing_token', 'processing_lease_expires_at', 'last_dispatched_at', 'checkpoint_line', 'total_rows', 'inserted_rows', 'duplicate_rows', 'rejected_rows', 'errors', 'processed_at'];

    protected function casts(): array
    {
        return ['received_at' => 'immutable_datetime', 'processing_lease_expires_at' => 'immutable_datetime', 'processed_at' => 'immutable_datetime', 'errors' => 'array'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function deviceCommand(): BelongsTo
    {
        return $this->belongsTo(DeviceCommand::class);
    }

    public function punches(): HasMany
    {
        return $this->hasMany(AttendancePunch::class);
    }
}
