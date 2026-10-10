<?php

return [
    'model_label' => 'شركة', 'plural_model_label' => 'الشركات',
    'fields' => ['name' => 'الاسم', 'code' => 'الرمز', 'timezone' => 'المنطقة الزمنية', 'is_active' => 'نشطة'],
    'columns' => ['name' => 'الاسم', 'code' => 'الرمز', 'timezone' => 'المنطقة الزمنية', 'devices_count' => 'الأجهزة'],
    'api_client' => [
        'create' => 'إنشاء عميل API',
        'heading' => 'إنشاء عميل API لشركة :company',
        'username' => 'اسم مستخدم API',
        'password' => 'كلمة المرور',
        'password_confirmation' => 'تأكيد كلمة المرور',
        'created' => 'تم إنشاء عميل API',
        'instructions' => 'أرسل اسم المستخدم وكلمة المرور إلى POST /jwt-api-token-auth/ للحصول على رمز. استخدم Authorization: Token <token> لقراءة الحركات. احفظ بيانات الدخول؛ لا يمكن استرجاع كلمة المرور لاحقاً.',
    ],
];
