<?php

use App\Models\User;
use Filament\Panel;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    config(['app.env' => 'production']);
});

it('allows authorized users into the admin panel in production', function (string $roleName) {
    $user = User::factory()->create();
    $role = Role::create(['name' => $roleName, 'guard_name' => 'web']);
    $user->assignRole($role);

    $this->actingAs($user)
        ->get(route('filament.admin.pages.dashboard'))
        ->assertOk();
})->with(['super_admin', 'panel_user']);

it('returns 403 for users without a panel access role in production', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('filament.admin.pages.dashboard'))
        ->assertForbidden();
});

it('redirects guests to the admin login in production', function () {
    $this->get(route('filament.admin.pages.dashboard'))
        ->assertRedirect(route('filament.admin.auth.login'));
});

it('denies access to unconfigured panels even for a super admin', function () {
    $user = User::factory()->create();
    $role = Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
    $user->assignRole($role);

    expect($user->canAccessPanel(Panel::make()->id('other')))->toBeFalse();
});

it('keeps a database backed local admin session authenticated across refreshes', function () {
    config(['app.env' => 'local', 'session.driver' => 'database']);
    $user = User::factory()->create();
    $user->assignRole(Role::create(['name' => 'super_admin', 'guard_name' => 'web']));
    $loginKey = auth('web')->getName();

    $response = $this->withSession([$loginKey => $user->id])
        ->get(route('filament.admin.pages.dashboard'))->assertOk();
    $sessionCookie = collect($response->headers->getCookies())
        ->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));
    $this->app['auth']->forgetGuards();

    $this->withUnencryptedCookie($sessionCookie->getName(), $sessionCookie->getValue())
        ->get(route('filament.admin.pages.dashboard'))->assertOk();
    $this->app['auth']->forgetGuards();
    $this->get(route('filament.admin.pages.dashboard'))->assertOk();
});

it('keeps the admin session authenticated after a livewire dashboard interaction', function () {
    config(['app.env' => 'local', 'session.driver' => 'database']);
    $user = User::factory()->create();
    $user->assignRole(Role::create(['name' => 'super_admin', 'guard_name' => 'web']));

    $page = $this->withSession([auth('web')->getName() => $user->id])
        ->get(route('filament.admin.pages.dashboard'))->assertOk();
    $sessionId = session()->getId();
    preg_match('/wire:snapshot="([^"]+)"/', $page->getContent(), $matches);
    $snapshot = html_entity_decode($matches[1], ENT_QUOTES);
    $cookieName = config('session.cookie');
    $cookie = collect($page->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === $cookieName);
    $this->app['auth']->forgetGuards();
    $this->app['session']->forgetDrivers();

    $interaction = $this->withUnencryptedCookie($cookieName, $cookie->getValue())
        ->withCredentials()
        ->withHeader('X-Livewire', 'true')
        ->postJson(Livewire::getUpdateUri(), [
            'components' => [[
                'snapshot' => $snapshot,
                'updates' => [],
                'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
            ]],
        ])->assertOk();
    expect(session()->getId())->toBe($sessionId);
    $cookie = collect($interaction->headers->getCookies())->first(fn ($cookie) => $cookie->getName() === $cookieName);
    $this->app['auth']->forgetGuards();
    $this->app['session']->forgetDrivers();

    $this->withUnencryptedCookie($cookieName, $cookie->getValue())
        ->get(route('filament.admin.pages.dashboard'))->assertOk();
});
