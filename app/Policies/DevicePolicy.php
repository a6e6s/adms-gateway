<?php

namespace App\Policies;

class DevicePolicy extends CompanyRecordPolicy
{
    protected string $subject = 'Device';
}
