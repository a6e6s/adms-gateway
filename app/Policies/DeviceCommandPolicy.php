<?php

namespace App\Policies;

class DeviceCommandPolicy extends CompanyRecordPolicy
{
    protected string $subject = 'DeviceCommand';
}
