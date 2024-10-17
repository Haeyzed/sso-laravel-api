<?php

use App\Models\User;
use App\Enums\SocialProviderEnum;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);
uses(Illuminate\Foundation\Testing\WithFaker::class);

//beforeEach(function () {
//    $this->artisan('passport:install');
//});

test('redirect to provider', function () {
    $response = $this->getJson('/api/v1/auth/redirect/' . SocialProviderEnum::GOOGLE->value);

    $response->assertStatus(200);
    $response->assertJsonStructure(['data' => ['url']]);
});

test('handle provider callback', function () {
    $socialiteUser = new SocialiteUser();
    $socialiteUser->id = '123456';
    $socialiteUser->name = 'Test User';
    $socialiteUser->email = 'test@example.com';

    Socialite::shouldReceive('driver->stateless->user')
        ->andReturn($socialiteUser);

    $response = $this->getJson('/api/v1/auth/callback/' . SocialProviderEnum::GOOGLE->value);

    $response->assertStatus(200);
    $response->assertJsonStructure(['data' => ['user', 'access_token']]);
});

test('login', function () {
    $user = User::factory()->create([
        'email' => 'test@example.com',
        'password' => Hash::make('password'),
        'email_verified_at' => now(),
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'test@example.com',
        'password' => 'password',
    ]);

    $response->assertStatus(200);
    $response->assertJsonStructure(['data' => ['user', 'access_token']]);
});

test('login with unverified email', function () {
    $user = User::factory()->create([
        'email' => 'test@example.com',
        'password' => Hash::make('password'),
        'email_verified_at' => null,
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'test@example.com',
        'password' => 'password',
    ]);

    $response->assertStatus(403);
    $response->assertJson(['message' => 'Your email is not verified. A new verification link has been sent to your email address.']);
});

test('issue passport token', function () {
    $user = User::factory()->create();
    $client = $user->clients()->create([
        'name' => 'Test Client',
        'secret' => 'test_secret',
        'provider' => 'users',
        'redirect' => 'http://localhost',
        'personal_access_client' => true,
        'password_client' => true,
        'revoked' => false,
    ]);

    $response = $this->postJson('/api/v1/auth/token', [
        'grant_type' => 'password',
        'client_id' => $client->id,
        'client_secret' => 'test_secret',
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertStatus(200);
    $response->assertJsonStructure(['data' => ['user', 'access_token']]);
});

test('register', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'username' => 'testuser',
        'phone' => '1234567890',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertStatus(201);
    $response->assertJsonStructure(['data' => ['user', 'access_token']]);
});

test('verify email', function () {
    $user = User::factory()->create(['email_verified_at' => null]);

    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())]
    );

    $response = $this->getJson($verificationUrl);

    $response->assertStatus(200);
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('resend verification email', function () {
    $user = User::factory()->create(['email_verified_at' => null]);

    $response = $this->actingAs($user)->postJson('/api/v1/auth/email/resend');

    $response->assertStatus(200);
    $response->assertJson(['message' => 'Verification link sent']);
});

test('forgot password', function () {
    $user = User::factory()->create();

    $response = $this->postJson('/api/v1/auth/forgot-password', [
        'email' => $user->email,
    ]);

    $response->assertStatus(200);
    $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
});

test('reset password', function () {
    $user = User::factory()->create();
    $token = Password::createToken($user);

    $response = $this->postJson('/api/v1/auth/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'newpassword',
        'password_confirmation' => 'newpassword',
    ]);

    $response->assertStatus(200);
    expect(Hash::check('newpassword', $user->fresh()->password))->toBeTrue();
});

test('change password', function () {
    $user = User::factory()->create(['password' => Hash::make('oldpassword')]);

    $response = $this->actingAs($user)->postJson('/api/v1/auth/change-password', [
        'current_password' => 'oldpassword',
        'new_password' => 'newpassword',
        'new_password_confirmation' => 'newpassword',
    ]);

    $response->assertStatus(200);
    expect(Hash::check('newpassword', $user->fresh()->password))->toBeTrue();
});

test('get user profile', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/v1/auth/profile');

    $response->assertStatus(200);
    $response->assertJsonStructure(['data' => ['id', 'name', 'email']]);
});

test('update user profile', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->putJson('/api/v1/auth/profile', [
        'name' => 'Updated Name',
        'email' => 'updated@example.com',
    ]);

    $response->assertStatus(200);
    expect($user->fresh()->name)->toBe('Updated Name');
    expect($user->fresh()->email)->toBe('updated@example.com');
});

test('logout', function () {
    $user = User::factory()->create();
    $token = JWTAuth::fromUser($user);

    $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
        ->postJson('/api/v1/auth/logout');

    $response->assertStatus(200);
    $this->assertGuest();
});

test('refresh token', function () {
    $user = User::factory()->create();
    $token = JWTAuth::fromUser($user);

    $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
        ->postJson('/api/v1/auth/refresh');

    $response->assertStatus(200);
    $response->assertJsonStructure(['data' => ['access_token']]);
});
