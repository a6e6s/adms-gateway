<?php

return [
    'model_label' => 'device command', 'plural_model_label' => 'device commands',
    'columns' => [
        'device' => 'Device', 'type' => 'Command', 'wire_command_id' => 'Wire ID', 'query_start_time' => 'From (device time)',
        'query_end_time' => 'Through (device time)', 'status' => 'Status', 'requested_at' => 'Requested at', 'offered_at' => 'Offered at',
        'wake_sent_at' => 'UDP wake sent', 'wake_error' => 'Wake issue', 'result_received_at' => 'Result received at',
        'attendance_uploads_count' => 'Matching uploads', 'expires_at' => 'Expires at', 'raw_result' => 'Device response',
    ],
    'filters' => [
        'device' => 'Device', 'type' => 'Command', 'status' => 'Status', 'requested_at' => 'Requested date',
        'from' => 'From', 'until' => 'Until',
    ],
    'option_fields' => [
        'serial_number' => 'Serial Number', 'firmware_version' => 'Firmware Version', 'device_name' => 'Device Name',
        'ip_address' => 'IP Address', 'network_mask' => 'Network Mask', 'gateway_ip_address' => 'Gateway IP Address',
        'communication_password' => 'Communication Password',
    ],
    'not_applicable' => 'Not applicable',
    'sensitive_value_hidden' => 'Hidden',
    'actions' => [
        'view_result' => 'View result', 'view_result_heading' => 'Device command response', 'close' => 'Close',
        'download_query_data' => 'Download query data',
        'cancel_pending' => 'Cancel command', 'cancel_pending_heading' => 'Cancel this command?',
        'cancel_pending_description' => 'This only cancels commands that have not been sent to the device yet.',
        'cancel_pending_success' => 'The pending command was cancelled.',
        'cancel_pending_unavailable' => 'This command was already sent or cancelled.',
    ],
    'no_result' => 'No response received yet.',
    'types' => ['request_attendance' => 'Attendance range query', 'force_resend_attendance' => 'Force full history resend'],
    'statuses' => [
        'pending' => 'Waiting for device poll', 'offered' => 'Sent to device', 'acknowledged' => 'Accepted by device',
        'attendance_received' => 'Matching attendance received', 'unknown' => 'Delivery uncertain',
        'expired' => 'Expired before delivery', 'cancelled' => 'Cancelled before delivery', 'failed' => 'Device rejected request',
    ],
];
