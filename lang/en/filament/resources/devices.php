<?php

return [
    'model_label' => 'device', 'plural_model_label' => 'devices',
    'fields' => [
        'company' => 'Company', 'serial_number' => 'Serial number', 'name' => 'Name', 'location' => 'Location',
        'expected_ip' => 'Expected IP address', 'timezone' => 'Time zone', 'protocol_profile' => 'Protocol profile',
        'is_enabled' => 'Enabled', 'start_time' => 'Start time (application time zone)', 'end_time' => 'End time (application time zone)',
    ],
    'columns' => [
        'serial_number' => 'Serial number', 'name' => 'Name', 'company' => 'Company', 'push_version' => 'Push version',
        'connection_status' => 'Connection status', 'last_seen_at' => 'Last seen', 'last_getrequest_at' => 'Last command poll', 'attlog_stamp' => 'Attendance stamp',
    ],
    'statuses' => ['online' => 'Online', 'offline' => 'Offline'],
    'placeholders' => ['not_received' => 'Not received'],
    'actions' => [
        'request_attendance' => 'Request attendance', 'force_resend' => 'Force full history resend',
        'request_attendance_heading' => 'Request stored attendance for the selected devices?',
        'force_resend_heading' => 'Force a full history resend for the selected devices?',
        'bulk_attendance_description' => 'The selected time range is interpreted in the application time zone and converted to each device time zone. Commands are delivered on the next device poll. Existing punches are deduplicated.',
    ],
    'notifications' => [
        'commands_queued' => ':count attendance command(s) queued.',
        'commands_skipped' => ':count command(s) could not be queued. Check device status and pending commands.',
    ],
];
