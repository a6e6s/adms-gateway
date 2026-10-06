<?php

return [
    'model_label' => 'attendance upload', 'plural_model_label' => 'attendance uploads',
    'columns' => [
        'id' => 'Receipt', 'device' => 'Device', 'received_at' => 'Received at', 'byte_count' => 'Bytes', 'status' => 'Status',
        'total_rows' => 'Rows', 'inserted_rows' => 'Inserted', 'duplicate_rows' => 'Duplicates', 'rejected_rows' => 'Rejected',
    ],
    'statuses' => [
        'pending' => 'Pending', 'processing' => 'Processing', 'processed' => 'Processed',
        'processed_with_errors' => 'Processed with errors', 'failed' => 'Failed',
    ],
    'actions' => ['retry' => 'Retry processing'],
    'notifications' => ['retry_queued' => 'Upload queued for processing'],
];
