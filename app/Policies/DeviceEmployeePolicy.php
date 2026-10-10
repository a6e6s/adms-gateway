<?php

namespace App\Policies;

class DeviceEmployeePolicy extends CompanyRecordPolicy
{
    protected string $subject = 'DeviceEmployee';
}
