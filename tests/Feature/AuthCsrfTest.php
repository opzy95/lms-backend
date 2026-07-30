<?php

it('allows the public login endpoint to be called without a csrf token', function () {
    $response = $this->postJson('/api/login', [
        'email' => 'invalid-email',
        'password' => 'secret',
    ]);

    $response->assertStatus(422);
});
