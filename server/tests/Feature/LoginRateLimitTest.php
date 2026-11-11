<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sign-in limit: five attempts a minute at one account, twenty a minute
 * from one address. Attempts at one account must not use up another's.
 */
class LoginRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private function attempt(array $fields)
    {
        return $this->postJson('/api/login', $fields + ['password' => 'not-the-password']);
    }

    public function test_one_account_is_limited_after_five_attempts_a_minute(): void
    {
        foreach (range(1, 5) as $ignored) {
            $this->assertNotSame(429, $this->attempt(['login' => 'someone@example.test'])->status());
        }

        $this->attempt(['login' => 'someone@example.test'])->assertStatus(429);
    }

    public function test_attempts_at_one_account_do_not_lock_out_another_on_the_same_network(): void
    {
        foreach (range(1, 5) as $ignored) {
            $this->attempt(['login' => 'first@example.test']);
        }

        $this->assertNotSame(429, $this->attempt(['login' => 'second@example.test'])->status());
    }

    public function test_one_account_is_the_same_account_however_it_is_typed(): void
    {
        foreach (['Someone@Example.test', ' someone@example.test ', 'SOMEONE@EXAMPLE.TEST', 'someone@example.test', 'someone@example.test'] as $typed) {
            $this->attempt(['login' => $typed]);
        }

        $this->attempt(['login' => 'someone@example.test'])->assertStatus(429);

        foreach (['0917 123 4567', '0917-123-4567', '09171234567', '0917.123.4567', '(0917) 1234567'] as $typed) {
            $this->attempt(['login' => $typed]);
        }

        $this->attempt(['login' => '09171234567'])->assertStatus(429);
    }

    public function test_the_older_email_field_is_still_limited(): void
    {
        foreach (range(1, 5) as $ignored) {
            $this->attempt(['email' => 'someone@example.test']);
        }

        $this->attempt(['email' => 'someone@example.test'])->assertStatus(429);
    }

    public function test_one_address_is_limited_after_twenty_attempts_a_minute_across_accounts(): void
    {
        foreach (range(1, 20) as $n) {
            $this->assertNotSame(429, $this->attempt(['login' => "person{$n}@example.test"])->status());
        }

        $this->attempt(['login' => 'person21@example.test'])->assertStatus(429);
    }
}
