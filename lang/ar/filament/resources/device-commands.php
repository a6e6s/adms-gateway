<?php

return [
    'model_label' => 'أمر جهاز', 'plural_model_label' => 'أوامر الأجهزة',
    'columns' => [
        'device' => 'الجهاز', 'type' => 'الأمر', 'wire_command_id' => 'معرّف البروتوكول', 'query_start_time' => 'من (وقت الجهاز)',
        'query_end_time' => 'إلى (وقت الجهاز)', 'status' => 'الحالة', 'requested_at' => 'وقت الطلب', 'offered_at' => 'وقت الإرسال',
        'wake_sent_at' => 'وقت إرسال UDP', 'wake_error' => 'مشكلة الإيقاظ', 'result_received_at' => 'وقت استلام النتيجة',
        'attendance_uploads_count' => 'عمليات الرفع المطابقة', 'expires_at' => 'وقت الانتهاء', 'raw_result' => 'استجابة الجهاز',
    ],
    'filters' => [
        'device' => 'الجهاز', 'type' => 'الأمر', 'status' => 'الحالة', 'requested_at' => 'تاريخ الطلب',
        'from' => 'من', 'until' => 'إلى',
    ],
    'option_fields' => [
        'serial_number' => 'الرقم التسلسلي', 'firmware_version' => 'إصدار البرنامج الثابت', 'device_name' => 'اسم الجهاز',
        'ip_address' => 'عنوان IP', 'network_mask' => 'قناع الشبكة', 'gateway_ip_address' => 'عنوان البوابة',
        'communication_password' => 'كلمة مرور الاتصال',
    ],
    'not_applicable' => 'غير متاح',
    'sensitive_value_hidden' => 'مخفي',
    'actions' => [
        'view_result' => 'عرض النتيجة', 'view_result_heading' => 'استجابة أمر الجهاز', 'close' => 'إغلاق',
        'download_query_data' => 'تنزيل بيانات الاستعلام',
        'cancel_pending' => 'إلغاء الأمر', 'cancel_pending_heading' => 'هل تريد إلغاء هذا الأمر؟',
        'cancel_pending_description' => 'يمكن إلغاء الأوامر التي لم تُرسل إلى الجهاز فقط.',
        'cancel_pending_success' => 'تم إلغاء الأمر المعلّق.',
        'cancel_pending_unavailable' => 'تم إرسال هذا الأمر بالفعل أو إلغاؤه.',
    ],
    'no_result' => 'لم تصل استجابة بعد.',
    'types' => ['request_attendance' => 'طلب سجلات ضمن نطاق زمني', 'force_resend_attendance' => 'فرض إعادة إرسال السجل الكامل'],
    'statuses' => [
        'pending' => 'بانتظار اتصال الجهاز', 'offered' => 'أُرسل إلى الجهاز', 'acknowledged' => 'قبله الجهاز',
        'attendance_received' => 'تم استلام سجلات مطابقة', 'unknown' => 'حالة التسليم غير مؤكدة',
        'expired' => 'انتهت المهلة قبل الإرسال', 'cancelled' => 'أُلغي قبل الإرسال', 'failed' => 'رفض الجهاز الطلب',
    ],
];
