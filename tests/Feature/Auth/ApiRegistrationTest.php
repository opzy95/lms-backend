<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiRegistrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test successful registration with valid education level (basic)
     */
    public function test_registration_with_valid_basic_education_level()
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test Student',
            'email' => 'student@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'student',
            'education_level' => 'basic',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('user.education_level', 'basic');
        $response->assertJsonPath('user.role', 'student');
        $this->assertDatabaseHas('users', [
            'email' => 'student@example.com',
            'education_level' => 'basic',
        ]);
    }

    /**
     * Test successful registration with valid education level (secondary)
     */
    public function test_registration_with_valid_secondary_education_level()
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test Tutor',
            'email' => 'tutor@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'tutor',
            'education_level' => 'secondary',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('user.education_level', 'secondary');
        $response->assertJsonPath('user.role', 'tutor');
        $this->assertDatabaseHas('users', [
            'email' => 'tutor@example.com',
            'education_level' => 'secondary',
        ]);
    }

    /**
     * Test registration fails with invalid education level (elementary)
     */
    public function test_registration_fails_with_invalid_education_level_elementary()
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'student',
            'education_level' => 'elementary',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('education_level');
        $this->assertDatabaseMissing('users', [
            'email' => 'test@example.com',
        ]);
    }

    /**
     * Test registration fails with invalid education level (tertiary)
     */
    public function test_registration_fails_with_invalid_education_level_tertiary()
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'student',
            'education_level' => 'tertiary',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('education_level');
        $this->assertDatabaseMissing('users', [
            'email' => 'test@example.com',
        ]);
    }

    /**
     * Test registration fails when education_level is missing
     */
    public function test_registration_fails_when_education_level_missing()
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'student',
            // education_level is intentionally missing
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('education_level');
        $this->assertDatabaseMissing('users', [
            'email' => 'test@example.com',
        ]);
    }

    /**
     * Test registration fails with empty education_level
     */
    public function test_registration_fails_with_empty_education_level()
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'student',
            'education_level' => '',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('education_level');
        $this->assertDatabaseMissing('users', [
            'email' => 'test@example.com',
        ]);
    }

    /**
     * Test registration fails with null education_level
     */
    public function test_registration_fails_with_null_education_level()
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'student',
            'education_level' => null,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('education_level');
        $this->assertDatabaseMissing('users', [
            'email' => 'test@example.com',
        ]);
    }

    /**
     * Test registration fails with whitespace-only education_level
     */
    public function test_registration_fails_with_whitespace_education_level()
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'student',
            'education_level' => '   ',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('education_level');
        $this->assertDatabaseMissing('users', [
            'email' => 'test@example.com',
        ]);
    }

    /**
     * Test registration includes token when successful
     */
    public function test_registration_returns_token_on_success()
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'student',
            'education_level' => 'basic',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure(['token']);
        $this->assertNotNull($response->json('token'));
    }

    /**
     * Test case sensitivity - education_level should be case-sensitive
     */
    public function test_registration_fails_with_uppercase_education_level()
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'student',
            'education_level' => 'BASIC',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('education_level');
    }

    /**
     * Test registration fails with mixed case education_level
     */
    public function test_registration_fails_with_mixed_case_education_level()
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'student',
            'education_level' => 'Basic',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('education_level');
    }
}
