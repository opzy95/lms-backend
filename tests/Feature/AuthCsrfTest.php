<?php

it('validates credentials on the public login endpoint', function () {
    $response = $this->postJson('/api/login', [
        'email' => 'invalid-email',
        'password' => 'secret',
    ]);

    $response->assertStatus(422);
});
