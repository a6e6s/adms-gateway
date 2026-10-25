@php
    $loginDirection = app()->isLocale('ar') ? 'rtl' : 'ltr';
@endphp

<div class="adms-login-page" dir="ltr">
    <section class="adms-login-welcome" dir="{{ $loginDirection }}" aria-label="{{ __('filament.auth.welcome_heading') }}">
        <div class="adms-login-language">
            <svg viewBox="0 0 24 24" aria-hidden="true" fill="none">
                <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.8" />
                <path d="M3 12h18M12 3c2.2 2.4 3.3 5.4 3.3 9s-1.1 6.6-3.3 9c-2.2-2.4-3.3-5.4-3.3-9S9.8 5.4 12 3Z" stroke="currentColor" stroke-width="1.5" />
            </svg>
            @include('filament.partials.language-switcher')
        </div>

        <div class="adms-login-welcome-copy">
            <p class="adms-login-eyebrow">{{ __('filament.auth.welcome') }}</p>
            <h2>{{ __('filament.auth.welcome_heading') }}</h2>
            <p class="adms-login-description">{{ __('filament.auth.welcome_description') }}</p>
        </div>

        <div class="adms-login-illustration" aria-hidden="true">
            <div class="adms-login-plant">
                <svg viewBox="0 0 150 220" fill="none">
                    <path d="M77 157C75 112 73 72 75 33" stroke="#527d64" stroke-width="5" stroke-linecap="round" />
                    <path d="M74 79C49 71 33 55 28 31c24 1 43 13 48 38M75 111c-23-2-41-14-51-35 22-5 43 2 53 23M76 63c8-24 24-40 47-47 2 24-8 44-32 54M77 100c10-23 28-36 52-39-2 24-14 42-40 49" fill="#8eb69c" />
                    <path d="M28 31c24 1 43 13 48 38M24 76c22-5 43 2 53 23M123 16c2 24-8 44-32 54M128 61c-2 24-14 42-40 49" stroke="#648f73" stroke-width="2" stroke-linecap="round" />
                    <path d="m43 157 64 0-8 49H51l-8-49Z" fill="#f8fbfd" />
                    <path d="m43 157 64 0-2 12H45l-2-12Z" fill="#dbeaf1" />
                    <ellipse cx="75" cy="207" rx="42" ry="7" fill="#8eb2c4" fill-opacity=".22" />
                </svg>
            </div>

            <div class="adms-login-dashboard">
                <div class="adms-preview-sidebar">
                    <div class="adms-preview-brand"><span></span>{{ __('filament.brand') }}</div>
                    <i class="is-active"></i><i></i><i></i><i></i><i></i><i></i>
                </div>
                <div class="adms-preview-content">
                    <div class="adms-preview-topbar"><span></span><span></span><b></b></div>
                    <div class="adms-preview-metrics"><i></i><i></i><i></i></div>
                    <div class="adms-preview-charts">
                        <div class="adms-preview-chart">
                            <span></span><span></span><span></span><span></span><span></span><span></span><span></span>
                        </div>
                        <div class="adms-preview-donut"><span>95%</span></div>
                    </div>
                    <div class="adms-preview-table"><b></b><i></i><i></i><i></i></div>
                </div>
            </div>
        </div>
    </section>

    <section class="adms-login-panel" dir="{{ $loginDirection }}">
        <a href="{{ filament()->getHomeUrl() ?: '/' }}" class="adms-login-brand" dir="ltr" aria-label="{{ __('filament.brand') }}">
            <img src="{{ asset('images/adms-app-icon.svg') }}" alt="" aria-hidden="true">
            <span dir="auto">{{ __('filament.brand') }}</span>
        </a>

        <div class="adms-login-form-wrap">
            <h1>{{ $this->getHeading() }}</h1>
            <p class="adms-login-form-subheading">
                {{ filled($this->userUndertakingMultiFactorAuthentication) ? $this->getSubheading() : __('filament.auth.login_subheading') }}
            </p>

            <div class="adms-login-form">
                {{ $this->content }}
            </div>

            <div class="adms-login-security">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M12 3 20 6v5c0 5-3.4 8.2-8 10-4.6-1.8-8-5-8-10V6l8-3Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" />
                    <path d="m9 12 2 2 4-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                <div>
                    <strong>{{ __('filament.auth.security_heading') }}</strong>
                    <span>{{ __('filament.auth.security_description') }}</span>
                </div>
            </div>

            <p class="adms-login-copyright">© {{ now()->year }} ADMS. {{ __('filament.auth.copyright') }}</p>
        </div>
    </section>
</div>
