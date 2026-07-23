<?php

use App\Models\User;

test('student courses endpoint is available', function () {
    $user = User::factory()->create([
        'role' => 'student',
    ]);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/student/courses');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'message',
            'data',
            'pagination',
        ]);
});
