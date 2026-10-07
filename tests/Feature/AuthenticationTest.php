<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $signedIn = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    private function user(): User
    {
        return User::create(['username' => 'thomas', 'name' => 'Thomas', 'password' => 'geheim-wachtwoord']);
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $stream = Player::create(['name' => 'Sophie'])->streams()->create(['title' => 'Stream', 'started_at' => now(), 'source' => 'test']);

        foreach (['/', '/dashboard', '/streams', '/players', '/settings', "/streams/{$stream->id}", "/streams/{$stream->id}/audio"] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        $this->post('/streams/'.$stream->id.'/transcribe')->assertRedirect('/login');
        $this->getJson("/streams/{$stream->id}/transcription-status")->assertUnauthorized();
        $this->assertSame('pending', $stream->fresh()->transcription_status);
    }

    public function test_the_login_page_renders_for_guests(): void
    {
        $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Auth/Login')->where('auth.user', null));
    }

    public function test_a_user_logs_in_and_returns_to_the_page_they_asked_for(): void
    {
        $user = $this->user();
        $this->get('/players');

        $this->post('/login', ['username' => 'Thomas ', 'password' => 'geheim-wachtwoord'])->assertRedirect('/players');
        $this->assertAuthenticatedAs($user);
        $this->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page->where('auth.user.name', 'Thomas'));
    }

    public function test_a_wrong_password_is_refused(): void
    {
        $this->user();

        $this->from('/login')->post('/login', ['username' => 'thomas', 'password' => 'fout'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['username' => 'Deze combinatie van gebruikersnaam en wachtwoord klopt niet.']);
        $this->assertGuest();
    }

    public function test_too_many_attempts_are_throttled(): void
    {
        $this->user();

        foreach (range(1, 5) as $attempt) {
            $this->post('/login', ['username' => 'thomas', 'password' => 'fout']);
        }
        $this->post('/login', ['username' => 'thomas', 'password' => 'geheim-wachtwoord'])
            ->assertSessionHasErrors('username');
        $this->assertGuest();
        RateLimiter::clear('thomas|127.0.0.1');
    }

    public function test_a_logged_in_user_skips_the_login_page_and_can_log_out(): void
    {
        $this->actingAs($this->user());
        $this->get('/login')->assertRedirect('/dashboard');

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_the_worker_api_does_not_need_a_login(): void
    {
        config(['services.workers.token' => 'secret']);

        $this->withToken('secret')->postJson('/api/workers/heartbeat', [
            'name' => 'laptop', 'url' => 'http://laptop:8001', 'backend' => 'cuda', 'capabilities' => ['transcribe'], 'busy' => false,
        ])->assertSuccessful();
        $this->assertGuest();
    }

    public function test_the_command_creates_a_user_and_resets_a_password(): void
    {
        $this->artisan('user:create', ['--username' => 'Thomas', '--name' => 'Thomas', '--password' => 'eerste-wachtwoord'])->assertSuccessful();
        $this->artisan('user:create', ['--username' => 'thomas', '--password' => 'tweede-wachtwoord'])->assertSuccessful();

        $this->assertSame(1, User::count());
        $this->post('/login', ['username' => 'thomas', 'password' => 'tweede-wachtwoord'])->assertRedirect('/dashboard');
        $this->artisan('user:create', ['--username' => 'x', '--name' => 'X', '--password' => 'kort'])->assertFailed();
        $this->artisan('user:create', ['--username' => 'met spatie', '--name' => 'X', '--password' => 'lang-genoeg'])->assertFailed();
    }

    public function test_the_admin_seeder_creates_the_default_account_once(): void
    {
        $this->seed(AdminUserSeeder::class);
        $this->artisan('user:create', ['--username' => 'Thoompje', '--password' => 'nieuw-wachtwoord'])->assertSuccessful();
        $this->seed(AdminUserSeeder::class);

        $this->assertSame(1, User::count());
        $this->post('/login', ['username' => 'thoompje', 'password' => 'nieuw-wachtwoord'])->assertRedirect('/dashboard');
    }
}
