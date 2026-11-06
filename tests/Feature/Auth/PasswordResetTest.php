<?php

use App\Models\AuthenticationCode;
use App\Models\User;
use App\Notifications\ForgotPasswordCodeNotification;
use App\Services\AuthenticationCodeService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

function passwordRecoveryCodeFor(User $user): string
{
    $notification = Notification::sent(
        $user,
        ForgotPasswordCodeNotification::class
    )->sole();

    return $notification->toMail($user)->viewData['code'];
}

test('reset password recovery request screen can be rendered', function () {
    $this->get(route('password.request'))->assertOk();
});

test('password recovery code can be requested without exposing account existence', function () {
    Notification::fake();

    $user = User::factory()->create();

    $response = $this->post(route('password.email'), [
        'email' => strtoupper($user->email),
    ]);

    $response
        ->assertRedirect(route('password.verify'))
        ->assertSessionHas('password_recovery_email', strtolower($user->email))
        ->assertSessionHas(
            'status',
            'If an account is associated with that email address, a verification code has been sent.'
        );

    Notification::assertSentTo($user, ForgotPasswordCodeNotification::class);

    $code = passwordRecoveryCodeFor($user);
    $authenticationCode = AuthenticationCode::query()
        ->where('user_id', $user->id)
        ->where('type', AuthenticationCodeService::TYPE_FORGOT_PASSWORD)
        ->sole();

    expect($authenticationCode->code_hash)->not->toBe($code)
        ->and(Hash::check($code, $authenticationCode->code_hash))->toBeTrue()
        ->and($authenticationCode->expires_at->isFuture())->toBeTrue()
        ->and($authenticationCode->used_at)->toBeNull();

    $this->post(route('password.email'), [
        'email' => 'unknown@example.com',
    ])
        ->assertRedirect(route('password.verify'))
        ->assertSessionHas(
            'status',
            'If an account is associated with that email address, a verification code has been sent.'
        );

    Notification::assertCount(1);
});

test('password recovery verification screen can be rendered after requesting a code', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email])
        ->assertRedirect(route('password.verify'));

    Notification::assertSentTo($user, ForgotPasswordCodeNotification::class);

    $this->get(route('password.verify'))
        ->assertOk()
        ->assertViewIs('auth.verify-forgot-password');
});

test('password can be reset after a valid recovery code verification', function () {
    Notification::fake();

    $user = User::factory()->create([
        'two_factor_verified_at' => now(),
    ]);

    $this->post(route('password.email'), ['email' => $user->email])
        ->assertRedirect(route('password.verify'));

    $code = passwordRecoveryCodeFor($user);

    $this->post(route('password.verify.submit'), ['code' => '000000'])
        ->assertSessionHasErrors('code');

    $this->post(route('password.verify.submit'), ['code' => $code])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('password.recovery'))
        ->assertSessionHas('password_recovery_user_id', $user->id)
        ->assertSessionHas('password_recovery_verified_at');

    expect(AuthenticationCode::query()
        ->where('user_id', $user->id)
        ->where('type', AuthenticationCodeService::TYPE_FORGOT_PASSWORD)
        ->value('used_at'))->not->toBeNull();

    $this->get(route('password.recovery'))
        ->assertOk()
        ->assertViewIs('auth.recovery-password');

    $newPassword = 'new-secure-password';

    $this->put(route('password.recovery.update'), [
        'password' => $newPassword,
        'password_confirmation' => $newPassword,
    ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('login'))
        ->assertSessionHas(
            'status',
            'Your password has been updated successfully. You can now log in with your new password.'
        )
        ->assertSessionMissing([
            'password_recovery_email',
            'password_recovery_user_id',
            'password_recovery_verified_at',
        ]);

    $user->refresh();

    expect(Hash::check($newPassword, $user->password))->toBeTrue()
        ->and($user->two_factor_verified_at)->toBeNull();
});
