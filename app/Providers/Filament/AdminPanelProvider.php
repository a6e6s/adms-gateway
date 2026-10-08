<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Http\Middleware\SetFilamentLocale;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName(fn (): string => __('filament.brand'))
            ->colors([
                'primary' => Color::Cyan,
                'gray' => Color::Slate,
            ])
            ->sidebarWidth('17rem')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->maxContentWidth(Width::Full)
            ->navigationGroups([
                NavigationGroup::make()->label(fn (): string => __('filament.navigation.groups.operations')),
                NavigationGroup::make()->label(fn (): string => __('filament.navigation.groups.people')),
                NavigationGroup::make()->label(fn (): string => __('filament.navigation.groups.devices')),
            ])
            // ->renderHook(PanelsRenderHook::TOPBAR_START, fn () => view('filament.partials.topbar-notification'))
            ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, fn () => view('filament.partials.language-switcher'))
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->middleware([
                SetFilamentLocale::class,
            ], isPersistent: true)
            ->plugins([
                FilamentShieldPlugin::make()->globallySearchable(false),
            ])
            ->authMiddleware([
                Authenticate::class,
                AuthenticateSession::class,
            ], isPersistent: true);
    }
}
