<div>
    <!-- The biggest battle is the war against ignorance. - Mustafa Kemal Atatürk -->
</div>
@php
    $code = (int) $code;
    $isArabic = app()->isLocale('ar');
    $copy = match ($code) {
        401 => [__('errors.401.title'), __('errors.401.message')],
        403 => [__('errors.403.title'), __('errors.403.message')],
        404 => [__('errors.404.title'), __('errors.404.message')],
        419 => [__('errors.419.title'), __('errors.419.message')],
        429 => [__('errors.429.title'), __('errors.429.message')],
        500 => [__('errors.500.title'), __('errors.500.message')],
        503 => [__('errors.503.title'), __('errors.503.message')],
        default => $code >= 500
            ? [__('errors.server.title'), __('errors.server.message')]
            : [__('errors.client.title'), __('errors.client.message')],
    };
@endphp

<!DOCTYPE html>
<html lang="{{ $isArabic ? 'ar' : 'en' }}" dir="{{ $isArabic ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#071b31">
    <title>{{ $code }} · {{ $copy[0] }} · {{ __('filament.brand') }}</title>
    <style>
        :root { color-scheme: light; font-family: Inter, "Segoe UI", Tahoma, Arial, sans-serif; color: #10213d; background: #f5faff; }
        * { box-sizing: border-box; }
        body { min-height: 100vh; margin: 0; background: radial-gradient(ellipse at 12% 90%, rgb(8 189 215 / 10%), transparent 30rem), radial-gradient(ellipse at 85% 8%, rgb(8 189 215 / 9%), transparent 28rem), linear-gradient(135deg, #f8fcff, #eff8fd); }
        .error-shell { display: flex; width: min(100% - 2rem, 76rem); min-height: 100vh; flex-direction: column; margin-inline: auto; padding-block: clamp(1.25rem, 4vh, 2.5rem); }
        .error-brand { display: inline-flex; align-items: center; gap: .65rem; align-self: flex-end; color: #10213d; font-size: 1.2rem; font-weight: 750; text-decoration: none; }
        .error-brand svg { width: 2rem; height: 2rem; }
        .error-main { display: grid; width: 100%; max-width: 66rem; grid-template-columns: minmax(15rem, .78fr) minmax(0, 1.22fr); align-items: center; gap: clamp(2rem, 6vw, 5rem); margin: auto; padding: clamp(1.5rem, 5vw, 4rem); border: 1px solid #dce7f1; border-radius: 1.75rem; background: rgb(255 255 255 / 90%); box-shadow: 0 24px 70px rgb(7 27 49 / 9%), inset 0 1px #fff; backdrop-filter: blur(14px); direction: ltr; }
        .error-art { position: relative; display: grid; min-height: 18rem; place-items: center; overflow: hidden; border-radius: 1.25rem; background: linear-gradient(145deg, #eaf9fd, #f5faff); }
        .error-art::before, .error-art::after { position: absolute; width: 13rem; height: 13rem; border: 1.5rem solid rgb(8 189 215 / 14%); border-radius: 50%; content: ""; }
        .error-art::before { top: -7rem; inset-inline-start: -6rem; }
        .error-art::after { right: -8rem; bottom: -8rem; width: 17rem; height: 17rem; border-color: rgb(8 189 215 / 9%); }
        .error-orbit { position: relative; z-index: 1; display: grid; width: 10.5rem; aspect-ratio: 1; place-items: center; border: 1px solid rgb(8 135 167 / 16%); border-radius: 50%; background: rgb(255 255 255 / 78%); box-shadow: 0 15px 38px rgb(7 27 49 / 10%); }
        .error-orbit::before { position: absolute; inset: .8rem; border: 1px dashed rgb(8 135 167 / 28%); border-radius: 50%; content: ""; }
        .error-code { color: #087f9f; font-size: 2.5rem; font-weight: 800; letter-spacing: -.06em; }
        .error-copy { direction: {{ $isArabic ? 'rtl' : 'ltr' }}; }
        .error-label { margin: 0 0 .85rem; color: #087f9f; font-size: .8rem; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; }
        h1 { margin: 0; color: #071b31; font-size: clamp(1.8rem, 4vw, 2.65rem); font-weight: 750; letter-spacing: -.04em; line-height: 1.3; }
        .error-message { margin: 1rem 0 0; color: #7185a1; font-size: 1rem; line-height: 1.9; }
        .error-actions { display: flex; flex-wrap: wrap; gap: .8rem; margin-block-start: 2rem; }
        .error-button { display: inline-flex; min-height: 3rem; align-items: center; justify-content: center; gap: .5rem; padding: .75rem 1.25rem; border: 1px solid transparent; border-radius: .75rem; background: linear-gradient(105deg, #08bdd7, #087f9f); box-shadow: 0 6px 16px rgb(8 135 167 / 18%); color: #fff; font-size: .95rem; font-weight: 650; text-decoration: none; transition: transform 150ms ease, box-shadow 150ms ease; }
        .error-button:hover { transform: translateY(-1px); box-shadow: 0 9px 20px rgb(8 135 167 / 23%); }
        .error-button-secondary { border-color: #dce7f1; background: #fff; box-shadow: none; color: #087f9f; }
        .error-footer { margin-block-start: 1.5rem; color: #91a2b8; font-size: .78rem; text-align: center; }
        @media (max-width: 700px) { .error-shell { width: min(100% - 1.25rem, 34rem); } .error-main { grid-template-columns: minmax(0, 1fr); gap: 1.5rem; padding: 1.25rem; border-radius: 1.25rem; } .error-art { min-height: 11rem; } .error-orbit { width: 7.5rem; } .error-code { font-size: 2rem; } .error-copy { padding: .25rem .25rem 1rem; text-align: center; } .error-actions { justify-content: center; } }
        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { scroll-behavior: auto !important; transition-duration: .01ms !important; } }
    </style>
</head>
<body>
    <div class="error-shell">
        <a href="/" class="error-brand" dir="ltr" aria-label="{{ __('filament.brand') }}">
            <span dir="auto">{{ __('filament.brand') }}</span>
            <svg viewBox="0 0 40 40" aria-hidden="true"><path d="M20 2 39 37H27L20 23 13 37H1L20 2Z" fill="#08bdd7" /></svg>
        </a>

        <main class="error-main">
            <div class="error-art" aria-hidden="true"><div class="error-orbit"><span class="error-code">{{ $code }}</span></div></div>
            <section class="error-copy">
                <p class="error-label">{{ __('errors.code_label', ['code' => $code]) }}</p>
                <h1>{{ $copy[0] }}</h1>
                <p class="error-message">{{ $copy[1] }}</p>
                <nav class="error-actions" aria-label="{{ __('errors.actions_label') }}">
                    <a href="/" class="error-button">{{ __('errors.home') }} <span aria-hidden="true">{{ $isArabic ? '←' : '→' }}</span></a>
                    <a href="/admin/login" class="error-button error-button-secondary">{{ __('errors.login') }}</a>
                </nav>
            </section>
        </main>

        <footer class="error-footer">© {{ date('Y') }} {{ __('filament.brand') }} · {{ __('errors.footer') }}</footer>
    </div>
</body>
</html>
