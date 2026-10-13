<?php

namespace App\Filament\Auth;

use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Support\Enums\Width;

class Login extends BaseLogin
{
    protected string $view = 'filament.auth.login';

    public function getMaxWidth(): Width|string|null
    {
        return Width::Full;
    }
}
