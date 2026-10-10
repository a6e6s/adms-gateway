<?php

return [
    'model_label' => 'company', 'plural_model_label' => 'companies',
    'fields' => ['name' => 'Name', 'code' => 'Code', 'timezone' => 'Time zone', 'is_active' => 'Active'],
    'columns' => ['name' => 'Name', 'code' => 'Code', 'timezone' => 'Time zone', 'devices_count' => 'Devices'],
    'api_client' => [
        'create' => 'Create API client',
        'heading' => 'Create API client for :company',
        'username' => 'API username',
        'password' => 'Password',
        'password_confirmation' => 'Confirm password',
        'created' => 'API client created',
        'instructions' => 'Send your username and password to POST /jwt-api-token-auth/ to obtain a token. Use Authorization: jwt <token> or Authorization: Token <token> when reading transactions. Save your credentials; the password cannot be retrieved later.',
    ],
];
