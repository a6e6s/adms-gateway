<?php

return [
    'model_label' => 'device command', 'plural_model_label' => 'device commands',
    'columns' => [
        'device' => 'Device', 'type' => 'Command', 'wire_command_id' => 'Wire ID', 'query_start_time' => 'From (device time)',
        'query_end_time' => 'Through (device time)', 'status' => 'Status', 'requested_at' => 'Requested at', 'offered_at' => 'Offered at',
        'wake_sent_at' => 'UDP wake sent', 'wake_error' => 'Wake issue', 'result_received_at' => 'Result received at',
        'attendance_uploads_count' => 'Matching uploads', 'expires_at' => 'Expires at',
    ],
    'types' => ['request_attendance' => 'Attendance range query', 'force_resend_attendance' => 'Force full history resend'],
    'statuses' => [
        'pending' => 'Waiting for device poll', 'offered' => 'Sent to device', 'acknowledged' => 'Accepted by device',
        'attendance_received' => 'Matching attendance received', 'unknown' => 'Delivery uncertain',
        'expired' => 'Expired before delivery', 'failed' => 'Device rejected request',
    ],
];
