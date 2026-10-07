<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected bool $signedIn = false;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->seed(AdminUserSeeder::class);
        $this->admin = User::where('username', 'thoompje')->firstOrFail();
    }

    public function test_only_the_seeded_account_is_admin(): void
    {
        $this->assertTrue($this->admin->isAdmin());
        $this->assertFalse(User::create(['username' => 'sophie', 'name' => 'Sophie', 'password' => 'wachtwoord'])->isAdmin());
    }

    public function test_the_admin_sees_the_accounts(): void
    {
        $this->actingAs($this->admin)->get('/users')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Users')
            ->where('auth.user.is_admin', true)
            ->has('users', 1)
            ->where('users.0.username', 'thoompje'));
    }

    public function test_the_admin_creates_an_account_that_can_log_in_and_is_not_admin(): void
    {
        $this->actingAs($this->admin)->from('/users')
            ->post('/users', ['username' => ' Sophie ', 'name' => 'Sophie', 'password' => 'sophie-wachtwoord', 'email' => User::ADMIN_EMAIL])
            ->assertRedirect('/users')->assertSessionHas('success');

        $sophie = User::where('username', 'sophie')->firstOrFail();
        $this->assertNull($sophie->email);
        $this->assertFalse($sophie->isAdmin());

        auth()->logout();
        $this->post('/login', ['username' => 'sophie', 'password' => 'sophie-wachtwoord'])->assertRedirect('/dashboard');
    }

    public function test_usernames_are_unique_and_validated(): void
    {
        $this->actingAs($this->admin);

        $this->post('/users', ['username' => 'THOOMPJE', 'name' => 'X', 'password' => 'lang-genoeg'])
            ->assertSessionHasErrors(['username' => 'Deze gebruikersnaam is al in gebruik.']);
        $this->post('/users', ['username' => 'met spatie', 'name' => 'X', 'password' => 'lang-genoeg'])->assertSessionHasErrors('username');
        $this->post('/users', ['username' => 'kort', 'name' => 'X', 'password' => 'kort'])->assertSessionHasErrors('password');
        $this->assertSame(1, User::count());
    }

    public function test_other_users_cannot_manage_accounts(): void
    {
        $sophie = User::create(['username' => 'sophie', 'name' => 'Sophie', 'password' => 'wachtwoord']);
        $this->actingAs($sophie);

        $this->get('/users')->assertForbidden();
        $this->post('/users', ['username' => 'lars', 'name' => 'Lars', 'password' => 'lang-genoeg'])->assertForbidden();
        $this->delete('/users/'.$this->admin->id)->assertForbidden();
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('auth.user.is_admin', false));
        $this->assertSame(2, User::count());
    }

    public function test_the_admin_removes_an_account_but_not_their_own(): void
    {
        $sophie = User::create(['username' => 'sophie', 'name' => 'Sophie', 'password' => 'wachtwoord']);
        $this->actingAs($this->admin);

        $this->delete('/users/'.$sophie->id)->assertSessionHas('success');
        $this->assertModelMissing($sophie);

        $this->delete('/users/'.$this->admin->id)->assertSessionHas('error');
        $this->assertModelExists($this->admin);
    }

    public function test_there_is_no_way_to_sign_up(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', ['username' => 'x', 'password' => 'lang-genoeg'])->assertNotFound();
    }
}
