<?php

return [
    'model_label' => 'جهاز', 'plural_model_label' => 'الأجهزة',
    'fields' => [
        'company' => 'الشركة', 'serial_number' => 'الرقم التسلسلي', 'name' => 'الاسم', 'location' => 'الموقع',
        'expected_ip' => 'عنوان IP المتوقع', 'timezone' => 'المنطقة الزمنية', 'protocol_profile' => 'ملف البروتوكول',
        'is_enabled' => 'مفعّل', 'start_time' => 'وقت البداية (المنطقة الزمنية للتطبيق)', 'end_time' => 'وقت النهاية (المنطقة الزمنية للتطبيق)',
    ],
    'columns' => [
        'serial_number' => 'الرقم التسلسلي', 'name' => 'الاسم', 'company' => 'الشركة', 'push_version' => 'إصدار Push',
        'connection_status' => 'حالة الاتصال', 'last_seen_at' => 'آخر اتصال', 'last_getrequest_at' => 'آخر طلب أوامر', 'attlog_stamp' => 'مؤشر الحضور',
    ],
    'statuses' => ['online' => 'متصل', 'offline' => 'غير متصل'],
    'placeholders' => ['not_received' => 'لم يُستقبل'],
    'actions' => [
        'request_attendance' => 'طلب سجلات الحضور', 'force_resend' => 'فرض إعادة إرسال السجل الكامل',
        'request_attendance_heading' => 'طلب سجلات الحضور المخزنة للأجهزة المحددة؟',
        'force_resend_heading' => 'فرض إعادة إرسال السجل الكامل للأجهزة المحددة؟',
        'bulk_attendance_description' => 'يُفسر النطاق الزمني وفق المنطقة الزمنية للتطبيق ثم يُحوّل إلى المنطقة الزمنية لكل جهاز. تُرسل الأوامر عند طلب الجهاز التالي. تُزال السجلات المكررة تلقائياً.',
    ],
    'notifications' => [
        'commands_queued' => 'تمت إضافة :count من أوامر الحضور إلى قائمة الانتظار.',
        'commands_skipped' => 'تعذرت إضافة :count من الأوامر. تحقق من حالة الجهاز والأوامر المعلقة.',
    ],
];
