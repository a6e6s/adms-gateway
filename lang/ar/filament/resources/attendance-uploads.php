<?php

return [
    'model_label' => 'رفع سجلات حضور', 'plural_model_label' => 'عمليات رفع الحضور',
    'columns' => [
        'id' => 'الإيصال', 'device' => 'الجهاز', 'received_at' => 'وقت الاستلام', 'byte_count' => 'الحجم بالبايت', 'status' => 'الحالة',
        'total_rows' => 'السجلات', 'inserted_rows' => 'المضافة', 'duplicate_rows' => 'المكررة', 'rejected_rows' => 'المرفوضة',
    ],
    'statuses' => [
        'pending' => 'قيد الانتظار', 'processing' => 'قيد المعالجة', 'processed' => 'تمت المعالجة',
        'processed_with_errors' => 'تمت المعالجة مع أخطاء', 'failed' => 'فشلت المعالجة',
    ],
    'actions' => ['retry' => 'إعادة المعالجة'],
    'notifications' => ['retry_queued' => 'تمت إضافة الرفع إلى قائمة المعالجة'],
];
