<?php

return [
    'model_label' => 'أمر جهاز', 'plural_model_label' => 'أوامر الأجهزة',
    'columns' => [
        'device' => 'الجهاز', 'type' => 'الأمر', 'wire_command_id' => 'معرّف البروتوكول', 'query_start_time' => 'من (وقت الجهاز)',
        'query_end_time' => 'إلى (وقت الجهاز)', 'status' => 'الحالة', 'requested_at' => 'وقت الطلب', 'offered_at' => 'وقت الإرسال',
        'wake_sent_at' => 'وقت إرسال UDP', 'wake_error' => 'مشكلة الإيقاظ', 'result_received_at' => 'وقت استلام النتيجة',
        'attendance_uploads_count' => 'عمليات الرفع المطابقة', 'expires_at' => 'وقت الانتهاء',
    ],
    'types' => ['request_attendance' => 'طلب سجلات ضمن نطاق زمني', 'force_resend_attendance' => 'فرض إعادة إرسال السجل الكامل'],
    'statuses' => [
        'pending' => 'بانتظار اتصال الجهاز', 'offered' => 'أُرسل إلى الجهاز', 'acknowledged' => 'قبله الجهاز',
        'attendance_received' => 'تم استلام سجلات مطابقة', 'unknown' => 'حالة التسليم غير مؤكدة',
        'expired' => 'انتهت المهلة قبل الإرسال', 'failed' => 'رفض الجهاز الطلب',
    ],
];
