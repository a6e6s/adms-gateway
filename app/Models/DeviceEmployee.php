<?php

namespace App\Models;

use Database\Factories\DeviceEmployeeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class DeviceEmployee extends Model
{
    /** @use HasFactory<DeviceEmployeeFactory> */
    use HasFactory;

    protected $fillable = ['device_id', 'employee_id', 'pin'];

    protected static function booted(): void
    {
        static::saving(function (DeviceEmployee $mapping): void {
            $device = Device::query()->find($mapping->device_id);
            $employee = Employee::query()->find($mapping->employee_id);

            if ($device === null || $employee === null || $device->company_id !== $employee->company_id) {
                throw ValidationException::withMessages([
                    'employee_id' => 'The employee and device must belong to the same company.',
                ]);
            }
        });
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function punches(): HasMany
    {
        return $this->hasMany(AttendancePunch::class);
    }
}
