<?php

use App\Models\User;

test('live classes index route works without a course id', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum');

    $response = $this->getJson('/api/live-classes');

    $response->assertOk()
        ->assertJsonStructure([
            'data',
        ]);
});
