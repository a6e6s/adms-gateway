<?php

namespace App\Models;

use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    protected $fillable = ['name', 'code', 'timezone', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function attendanceUploads(): HasMany
    {
        return $this->hasMany(AttendanceUpload::class);
    }

    public function attendancePunches(): HasMany
    {
        return $this->hasMany(AttendancePunch::class);
    }
}
