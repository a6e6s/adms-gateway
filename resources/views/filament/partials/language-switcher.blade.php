<form method="POST" action="{{ route('admin.language') }}" class="adms-language-switcher-form" aria-label="{{ __('filament.language.switch_to_arabic') }} / {{ __('filament.language.switch_to_english') }}">
    @csrf

    <button type="submit" name="locale" value="en" class="adms-language-option {{ app()->isLocale('en') ? 'is-active' : '' }}" aria-pressed="{{ app()->isLocale('en') ? 'true' : 'false' }}">EN</button>
    <button type="submit" name="locale" value="ar" class="adms-language-option {{ app()->isLocale('ar') ? 'is-active' : '' }}" aria-pressed="{{ app()->isLocale('ar') ? 'true' : 'false' }}" lang="ar">عربي</button>
</form>
