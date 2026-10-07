<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    protected bool $signedIn = false;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->user = User::create(['username' => 'thoompje', 'name' => 'Thoompje', 'password' => 'admin']);
        $this->actingAs($this->user);
    }

    public function test_the_profile_page_shows_the_account(): void
    {
        $this->get('/profile')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Profile')
            ->where('account.username', 'thoompje')
            ->where('account.name', 'Thoompje'));
    }

    public function test_the_password_is_changed_and_other_sessions_are_logged_out(): void
    {
        config(['session.driver' => 'database']);
        $oldRememberToken = $this->user->remember_token;
        DB::table('sessions')->insert([
            ['id' => 'other-browser', 'user_id' => $this->user->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'someone-else', 'user_id' => $this->user->id + 1, 'payload' => '', 'last_activity' => time()],
        ]);

        $this->from('/profile')->put('/profile/password', [
            'current_password' => 'admin',
            'password' => 'een-beter-wachtwoord',
            'password_confirmation' => 'een-beter-wachtwoord',
        ])->assertRedirect('/profile')->assertSessionHas('success');

        $this->user->refresh();
        $this->assertTrue(Hash::check('een-beter-wachtwoord', $this->user->password));
        $this->assertNotSame($oldRememberToken, $this->user->remember_token);
        // This browser's own (regenerated) session is saved too; the other browser's is gone.
        $sessions = DB::table('sessions')->pluck('id');
        $this->assertNotContains('other-browser', $sessions);
        $this->assertContains('someone-else', $sessions);
        $this->assertAuthenticatedAs($this->user);
    }

    public function test_a_wrong_current_password_is_refused(): void
    {
        $this->from('/profile')->put('/profile/password', [
            'current_password' => 'fout',
            'password' => 'een-beter-wachtwoord',
            'password_confirmation' => 'een-beter-wachtwoord',
        ])->assertRedirect('/profile')->assertSessionHasErrors(['current_password' => 'Het wachtwoord is onjuist.']);

        $this->assertTrue(Hash::check('admin', $this->user->fresh()->password));
    }

    public function test_the_new_password_must_be_long_enough_confirmed_and_new(): void
    {
        $this->put('/profile/password', ['current_password' => 'admin', 'password' => 'kort', 'password_confirmation' => 'kort'])
            ->assertSessionHasErrors(['password' => 'Nieuw wachtwoord moet minstens 8 tekens bevatten.']);
        $this->put('/profile/password', ['current_password' => 'admin', 'password' => 'lang-genoeg', 'password_confirmation' => 'iets-anders'])
            ->assertSessionHasErrors(['password' => 'De bevestiging van nieuw wachtwoord komt niet overeen.']);

        $this->assertTrue(Hash::check('admin', $this->user->fresh()->password));
    }

    public function test_guests_cannot_reach_the_profile(): void
    {
        auth()->logout();

        $this->get('/profile')->assertRedirect('/login');
        $this->put('/profile/password', ['current_password' => 'admin', 'password' => 'x', 'password_confirmation' => 'x'])->assertRedirect('/login');
    }
}
