<?php

return [
    'model_label' => 'attendance punch', 'plural_model_label' => 'attendance punches',
    'columns' => [
        'device_time' => 'Device time', 'pin' => 'PIN', 'employee' => 'Employee', 'device' => 'Device',
        'status' => 'Attendance status', 'verification_code' => 'Verification code', 'timezone' => 'Time zone', 'received_at' => 'Received at',
    ],
    'filters' => ['device' => 'Device'],
    'status_codes' => [
        'check_in' => 'Check-in', 'check_out' => 'Check-out', 'break_out' => 'Break-out',
        'break_in' => 'Break-in', 'overtime_in' => 'Overtime check-in', 'overtime_out' => 'Overtime check-out',
        'unknown' => 'Unknown status', 'unmapped' => 'Unmapped status (:code)',
    ],
];
