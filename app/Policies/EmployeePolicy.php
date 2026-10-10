<?php

namespace App\Policies;

class EmployeePolicy extends CompanyRecordPolicy
{
    protected string $subject = 'Employee';
}
