<?php

namespace App\Models;

use Database\Factories\DiscoveredDeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DiscoveredDevice extends Model
{
    /** @use HasFactory<DiscoveredDeviceFactory> */
    use HasFactory;

    protected $fillable = ['serial_number', 'last_seen_ip', 'push_version', 'device_type', 'first_seen_at', 'last_seen_at', 'attempt_count'];

    protected function casts(): array
    {
        return ['first_seen_at' => 'immutable_datetime', 'last_seen_at' => 'immutable_datetime', 'attempt_count' => 'integer'];
    }
}
