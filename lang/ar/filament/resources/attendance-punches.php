<?php

return [
    'model_label' => 'بصمة حضور', 'plural_model_label' => 'بصمات الحضور',
    'columns' => [
        'device_time' => 'وقت الجهاز', 'pin' => 'الرقم الشخصي', 'employee' => 'الموظف', 'device' => 'الجهاز',
        'status' => 'حالة الحضور', 'verification_code' => 'رمز التحقق', 'timezone' => 'المنطقة الزمنية', 'received_at' => 'وقت الاستلام',
    ],
    'filters' => ['device' => 'الجهاز'],
    'status_codes' => [
        'check_in' => 'تسجيل حضور', 'check_out' => 'تسجيل انصراف', 'break_out' => 'بدء استراحة',
        'break_in' => 'نهاية استراحة', 'overtime_in' => 'بدء عمل إضافي', 'overtime_out' => 'انتهاء عمل إضافي',
        'unknown' => 'حالة غير معروفة', 'unmapped' => 'حالة غير مصنفة (:code)',
    ],
];
