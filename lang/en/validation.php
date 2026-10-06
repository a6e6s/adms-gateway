<?php

return [
    'after_or_equal' => 'The :attribute must be a date after or equal to :date.',
    'boolean' => 'The :attribute field must be true or false.',
    'date' => 'The :attribute is not a valid date.',
    'email' => 'The :attribute must be a valid email address.',
    'integer' => 'The :attribute must be an integer.',
    'ip' => 'The :attribute must be a valid IP address.',
    'max' => ['string' => 'The :attribute may not be greater than :max characters.'],
    'min' => ['string' => 'The :attribute must be at least :min characters.'],
    'required' => 'The :attribute field is required.',
    'string' => 'The :attribute must be a string.',
    'timezone' => 'The :attribute must be a valid time zone.',
    'unique' => 'The :attribute has already been taken.',
    'before_or_equal' => 'The :attribute must be a date before or equal to :date.',
    'attributes' => [
        'company_id' => 'company', 'serial_number' => 'serial number', 'employee_number' => 'employee number',
        'expected_ip' => 'expected IP address', 'is_active' => 'active', 'is_enabled' => 'enabled',
        'start_time' => 'start time', 'end_time' => 'end time', 'protocol_profile' => 'protocol profile',
    ],
];
