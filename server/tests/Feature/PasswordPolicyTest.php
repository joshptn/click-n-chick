<?php

namespace Tests\Feature;

use App\Models\User;
use App\Rules\StrongPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Pins the policy as currently defined in App\Rules\StrongPassword - 8+
 * characters, an uppercase letter and a number - on every path that sets a
 * password. The register form mirrors these rules client-side; this is the
 * half that actually enforces them.
 *
 * NOTE: PRD FR-01.5 also lists a symbol. The rule no longer requires one, so
 * these tests follow the code rather than the PRD; see the report raised with
 * this change.
 */
class PasswordPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function registrationPayload(string $password): array
    {
        return [
            'email' => 'juan@example.test',
            'password' => $password,
            'password_confirmation' => $password,
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'phone_number' => '+639171234567',
        ];
    }

    public function test_a_compliant_password_is_accepted(): void
    {
        $this->postJson('/api/register', $this->registrationPayload('Password1!'))
            ->assertCreated();
    }

    /**
     * Laravel's Password::mixedCase() would also demand a lowercase letter.
     * The stated policy does not, so this must pass.
     */
    public function test_an_all_uppercase_password_satisfies_the_policy(): void
    {
        $this->postJson('/api/register', $this->registrationPayload('PASSWORD1!'))
            ->assertCreated();
    }

    public static function nonCompliantPasswords(): array
    {
        return [
            'too short' => ['Pw1!', 'at least 8 characters'],
            'no uppercase' => ['password1!', 'an uppercase letter'],
            'no number' => ['Password!', 'a number'],
        ];
    }

    /**
     * @dataProvider nonCompliantPasswords
     */
    public function test_a_password_missing_a_requirement_is_rejected(string $password, string $expected): void
    {
        $response = $this->postJson('/api/register', $this->registrationPayload($password))
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertStringContainsString($expected, $response->json('errors.password.0'));

        $this->assertDatabaseCount('users', 0);
    }

    public function test_every_unmet_requirement_is_named_in_a_single_message(): void
    {
        $response = $this->postJson('/api/register', $this->registrationPayload('abc'))
            ->assertStatus(422);

        $message = $response->json('errors.password.0');

        foreach (['8 characters', 'uppercase letter', 'number'] as $fragment) {
            $this->assertStringContainsString($fragment, $message);
        }
    }

    /** Password changes moved behind the BR-33 OTP; the policy still applies. */
    public function test_the_policy_applies_to_a_password_change(): void
    {
        $user = User::factory()->create([
            'account_status' => User::STATUS_ACTIVE,
            'password' => Hash::make('Original1!'),
        ]);

        $code = $this->requestPasswordCode($user);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/user/password', [
                'current_password' => 'Original1!',
                'password' => 'weakpassword',
                'password_confirmation' => 'weakpassword',
                'code' => $code,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check('Original1!', $user->fresh()->password));

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/user/password', [
                'current_password' => 'Original1!',
                'password' => 'Replacement1!',
                'password_confirmation' => 'Replacement1!',
                'code' => $code,
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('Replacement1!', $user->fresh()->password));
    }

    /** Requests a BR-33 code and returns the plaintext it was issued from. */
    private function requestPasswordCode(User $user): string
    {
        \Illuminate\Support\Facades\Mail::fake();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/user/password/request-code', ['channel' => 'email'])
            ->assertOk();

        $code = null;
        \Illuminate\Support\Facades\Mail::assertSent(
            \App\Mail\VerificationCodeMail::class,
            function ($mail) use (&$code) {
                $code = $mail->code;

                return true;
            }
        );

        return $code;
    }

    public function test_the_displayed_requirements_match_what_is_enforced(): void
    {
        // The register form renders this list; if the rule gains a requirement
        // without the list gaining a line, the two would drift apart silently.
        $this->assertSame(
            ['At least 8 characters', 'One uppercase letter', 'One number'],
            StrongPassword::requirements()
        );
    }
}
