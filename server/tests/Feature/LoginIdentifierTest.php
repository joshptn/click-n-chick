<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginIdentifierTest extends TestCase
{
    use RefreshDatabase;

    private function registeredUser(?string $phone = '+639171234567'): User
    {
        $user = User::factory()->create([
            'email' => 'rider@example.test',
            'password' => Hash::make('password123'),
        ]);

        $user->phone_number = $phone;
        $user->phone_number_hash = User::hashPhoneNumber($phone);
        $user->save();

        return $user->fresh();
    }

    // -----------------------------------------------------------------
    // The blind index itself
    // -----------------------------------------------------------------

    public function test_the_phone_formats_people_actually_type_all_normalize_alike(): void
    {
        $canonical = User::normalizePhoneNumber('+639171234567');

        foreach (['09171234567', '9171234567', '+63 917 123 4567', '+63 (917) 123-4567'] as $variant) {
            $this->assertSame($canonical, User::normalizePhoneNumber($variant), "failed for {$variant}");
            $this->assertSame(User::hashPhoneNumber('+639171234567'), User::hashPhoneNumber($variant));
        }
    }

    public function test_unparseable_input_yields_null_rather_than_a_wildcard(): void
    {
        foreach (['', 'not-a-number', '123', 'user@example.test'] as $junk) {
            $this->assertNull(User::normalizePhoneNumber($junk), "failed for '{$junk}'");
            $this->assertNull(User::hashPhoneNumber($junk));
        }
    }

    // -----------------------------------------------------------------
    // Signing in
    // -----------------------------------------------------------------

    public function test_login_with_email(): void
    {
        $this->registeredUser();

        $this->postJson('/api/login', ['login' => 'rider@example.test', 'password' => 'password123'])
            ->assertOk()
            ->assertJsonPath('user.email', 'rider@example.test')
            ->assertJsonStructure(['token']);
    }

    public function test_login_with_phone_number_in_any_accepted_format(): void
    {
        $this->registeredUser();

        foreach (['+639171234567', '09171234567', '9171234567', '+63 917 123 4567'] as $variant) {
            $this->postJson('/api/login', ['login' => $variant, 'password' => 'password123'])
                ->assertOk()
                ->assertJsonPath('user.email', 'rider@example.test');
        }
    }

    public function test_the_legacy_email_field_still_works(): void
    {
        $this->registeredUser();

        $this->postJson('/api/login', ['email' => 'rider@example.test', 'password' => 'password123'])
            ->assertOk();
    }

    // -----------------------------------------------------------------
    // Failure modes
    // -----------------------------------------------------------------

    public function test_a_wrong_password_is_rejected_with_401(): void
    {
        $this->registeredUser();

        $this->postJson('/api/login', ['login' => 'rider@example.test', 'password' => 'wrong-password'])
            ->assertStatus(401)
            ->assertJsonMissingPath('token');
    }

    public function test_an_unknown_account_is_indistinguishable_from_a_wrong_password(): void
    {
        $this->registeredUser();

        $unknown = $this->postJson('/api/login', ['login' => 'nobody@example.test', 'password' => 'password123']);
        $wrongPassword = $this->postJson('/api/login', ['login' => 'rider@example.test', 'password' => 'nope']);

        $unknown->assertStatus(401);
        $wrongPassword->assertStatus(401);

        // Same status and same body: no enumeration signal.
        $this->assertSame($wrongPassword->json('message'), $unknown->json('message'));
    }

    public function test_a_junk_identifier_never_matches_an_account_without_a_phone(): void
    {
        // The trap this guards: where('phone_number_hash', null) compiles to
        // "is null", which would match every phone-less account.
        $this->registeredUser(null);

        $this->postJson('/api/login', ['login' => 'not-a-number', 'password' => 'password123'])
            ->assertStatus(401);
    }

    public function test_the_identifier_is_required(): void
    {
        $this->postJson('/api/login', ['password' => 'password123'])
            ->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // Registration keeps the blind index in step
    // -----------------------------------------------------------------

    public function test_registering_populates_the_hash_so_phone_login_works_immediately(): void
    {
        $this->postJson('/api/register', [
            'first_name' => 'New',
            'last_name' => 'Rider',
            'email' => 'new@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'phone_number' => '+639998887777',
        ])->assertOk();

        $this->assertNotNull(User::where('email', 'new@example.test')->value('phone_number_hash'));

        $this->postJson('/api/login', ['login' => '09998887777', 'password' => 'password123'])
            ->assertOk()
            ->assertJsonPath('user.email', 'new@example.test');
    }

    public function test_a_duplicate_phone_number_is_a_422_not_a_driver_error(): void
    {
        $this->registeredUser();

        $this->postJson('/api/register', [
            'first_name' => 'Copy',
            'last_name' => 'Cat',
            'email' => 'copy@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'phone_number' => '09171234567',
        ])->assertStatus(422)->assertJsonValidationErrors('phone_number');
    }
}
