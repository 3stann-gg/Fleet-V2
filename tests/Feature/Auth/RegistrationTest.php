<?php

test('public registration screen is disabled', function () {
    $response = $this->get('/register');

    $response->assertNotFound();
});

test('public registration submissions are disabled', function () {
    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertGuest();
    $response->assertNotFound();
    $this->assertDatabaseMissing('users', [
        'email' => 'test@example.com',
    ]);
});
