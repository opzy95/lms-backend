<?php

use App\Models\User;

test('login screen can be rendered', function () {
    $response = $this->get('/login');

    $response->assertStatus(200);
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('api login authenticates a browser session without returning a bearer token', function () {
    $user = User::factory()->create();

    $response = $this->withHeader('Origin', 'http://localhost:5173')->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertOk()
        ->assertJsonMissingPath('token')
        ->assertCookie(config('session.cookie'));
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/logout');

    $this->assertGuest();
    $response->assertRedirect('/');
});

test('api logout revokes only the current access token', function () {
    $user = User::factory()->create();
    $currentToken = $user->createToken('current');
    $otherToken = $user->createToken('other');

    $response = $this->withToken($currentToken->plainTextToken)->postJson('/api/logout');

    $response->assertOk();
    $this->assertDatabaseMissing('personal_access_tokens', [
        'id' => $currentToken->accessToken->id,
    ]);
    $this->assertDatabaseHas('personal_access_tokens', [
        'id' => $otherToken->accessToken->id,
    ]);
});