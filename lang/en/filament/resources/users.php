<?php

return [
    'model_label' => 'User',
    'plural_model_label' => 'Users',
    'navigation_group' => 'Access Management',
    'fields' => ['name' => 'Name', 'email' => 'Email', 'password' => 'Password', 'companies' => 'Companies', 'roles' => 'Roles', 'created_at' => 'Created at'],
    'password_hint' => 'Use at least 8 characters. Leave blank when editing to keep the current password.',
    'super_admin_warning' => 'Leaving companies empty grants super-admin access to all companies. Only super admins can change this setting.',
    'roles_hint' => 'Roles control actions for company users. Panel access is automatic; users without companies receive the super-admin role.',
    'errors' => [
        'self_demote' => 'You cannot remove your own super-admin access.',
        'last_admin' => 'At least one super admin must remain.',
        'company_access' => 'You cannot select a record outside your assigned companies.',
    ],
];
