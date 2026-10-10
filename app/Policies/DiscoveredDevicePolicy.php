<?php

namespace App\Policies;

use App\Models\DiscoveredDevice;
use App\Models\User;

class DiscoveredDevicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function register(User $user, DiscoveredDevice $discoveredDevice): bool
    {
        return $user->isSuperAdmin();
    }
}
