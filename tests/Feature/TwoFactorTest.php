<?php

use App\Models\TwoFactorMethod;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FAQRCode\Google2FA;
use function Pest\Faker\faker;

it('is possible to create two factor methods', function () {
    $user = User::factory()->create();

    $google2fa = new Google2FA();
    $secret = $google2fa->generateSecretKey(32);
    $otp = $google2fa->getCurrentOtp($secret);

    $userData = ['name' => faker()->word(), 'two_factor_check' => $otp, 'two_factor_secret' => $secret];
    $this->actingAs($user)->post(route('users.twofactormethods.store', ['user' => $user]), $userData)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('users.twofactormethods.index', ['user' => $user]))
        ->assertSessionHas('2fa_method');

    expect($user->twoFactorMethods)->toHaveCount(1);
});

it('is possible to disable two factor methods', function () {
    $user = User::factory()
        ->has(TwoFactorMethod::factory()->enabled())
        ->create();
    $twofactormethod = $user->twoFactorMethods->first();

    $this->actingAs($user)
        ->session(['2fa_method' => $twofactormethod->id])
        ->put(route('users.twofactormethods.update', ['user' => $user, 'twofactormethod' => $twofactormethod]),
            ['enabled' => false])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success')
        ->assertRedirect(route('users.twofactormethods.index', ['user' => $user]));

    expect($twofactormethod->fresh()->attributesToArray())->toMatchArray(['enabled' => false]);
});

it('is possible to delete two factor methods', function () {
    $user = User::factory()
        ->has(TwoFactorMethod::factory()->enabled())
        ->create();
    $twofactormethod = $user->twoFactorMethods->first();

    $this->actingAs($user)
        ->session(['2fa_method' => $twofactormethod->id])
        ->delete(route('users.twofactormethods.destroy', ['user' => $user, 'twofactormethod' => $twofactormethod]))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success')
        ->assertRedirect(route('users.twofactormethods.index', ['user' => $user]));

    expect($user->fresh()->twoFactorMethods)->toBeEmpty();
});

it('is required to enter a 2FA when logging in with 2FA enabled', function () {
    $user = User::factory()->has(TwoFactorMethod::factory())->create([
        'email' => 'wouter@example.com',
        'password' => Hash::make('Welkom01!')
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertViewIs('2fa.enter_otp');
});

it('is possible to login with a valid 2FA code', function () {
    $user = User::factory()->has(TwoFactorMethod::factory())->create();
    $secret = decrypt($user->twoFactorMethods->first()->google2fa_secret);
    $google2fa = new Google2FA();
    $otp = $google2fa->getCurrentOtp($secret);

    $this->actingAs($user)
        ->post(route('2fa.verify_otp'), ['otp' => $otp])
        ->assertSessionMissing('error')
        ->assertSessionHasNoErrors()
        ->assertRedirect(RouteServiceProvider::HOME);
});

it('isn\'t possible to login with an invalid 2FA code', function () {
    $user = User::factory()->has(TwoFactorMethod::factory())->create();

    $this->actingAs($user)
        ->post(route('2fa.verify_otp'), ['otp' => (string) rand(100000, 999999)])
        ->assertSessionHas('error')
        ->assertRedirect(RouteServiceProvider::HOME);
});

