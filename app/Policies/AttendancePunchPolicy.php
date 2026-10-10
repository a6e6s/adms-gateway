<?php

namespace App\Policies;

class AttendancePunchPolicy extends CompanyRecordPolicy
{
    protected string $subject = 'AttendancePunch';
}
