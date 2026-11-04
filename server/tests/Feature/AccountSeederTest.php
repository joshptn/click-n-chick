<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The seeded accounts take every sign-in detail from the environment, because
 * the seeder itself is public.
 */
class AccountSeederTest extends TestCase
{
    use RefreshDatabase;

    private function configure(array $overrides = []): void
    {
        config(array_merge([
            'seeding.password' => 'Seeded-Pass-1',
            'seeding.accounts.super_admin.email' => 'manager@example.test',
            'seeding.accounts.super_admin.phone' => '+639171110001',
            'seeding.accounts.admin.email' => 'agent@example.test',
            'seeding.accounts.admin.phone' => '+639171110002',
            'seeding.accounts.customer.email' => 'buyer@example.test',
            'seeding.accounts.customer.phone' => '+639171110003',
        ], $overrides));
    }

    public function test_each_account_is_created_from_the_environment(): void
    {
        $this->configure();

        $this->seed(AccountSeeder::class);

        foreach ([
            'manager@example.test' => User::ROLE_SUPER_ADMIN,
            'agent@example.test' => User::ROLE_ADMIN,
            'buyer@example.test' => User::ROLE_CUSTOMER,
        ] as $email => $role) {
            $user = User::where('email', $email)->firstOrFail();

            $this->assertSame($role, $user->role);
            $this->assertTrue(Hash::check('Seeded-Pass-1', $user->password));
            $this->assertNotNull($user->email_verified_at);
        }
    }

    public function test_reseeding_resets_the_password_without_duplicating(): void
    {
        $this->configure();
        $this->seed(AccountSeeder::class);

        $this->configure(['seeding.password' => 'Rotated-Pass-2']);
        $this->seed(AccountSeeder::class);

        $this->assertSame(3, User::count());
        $this->assertTrue(Hash::check('Rotated-Pass-2', User::where('email', 'agent@example.test')->value('password')));
    }

    public function test_no_password_means_no_accounts(): void
    {
        $this->configure(['seeding.password' => null]);

        $this->seed(AccountSeeder::class);

        $this->assertSame(0, User::count());
    }

    public function test_an_account_with_a_missing_detail_is_skipped(): void
    {
        $this->configure(['seeding.accounts.admin.phone' => null]);

        $this->seed(AccountSeeder::class);

        $this->assertNull(User::where('email', 'agent@example.test')->first());
        $this->assertSame(2, User::count());
    }
}
