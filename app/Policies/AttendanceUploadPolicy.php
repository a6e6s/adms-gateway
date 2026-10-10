<?php

namespace App\Policies;

class AttendanceUploadPolicy extends CompanyRecordPolicy
{
    protected string $subject = 'AttendanceUpload';
}
