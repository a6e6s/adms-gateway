<?php

namespace App\Policies;

use App\Models\DiscoveredDevice;
use App\Models\User;

class DiscoveredDevicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(config('filament-shield.super_admin.name')) || $user->can('ViewAny:Device');
    }

    public function register(User $user, DiscoveredDevice $discoveredDevice): bool
    {
        return $user->hasRole(config('filament-shield.super_admin.name')) || $user->can('Create:Device');
    }
}
