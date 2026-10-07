<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Player;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    protected bool $signedIn = false;

    private User $admin;

    private User $sophie;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->seed(AdminUserSeeder::class);
        $this->admin = User::where('username', 'thoompje')->firstOrFail();
        $this->sophie = User::create(['username' => 'sophie', 'name' => 'Sophie', 'password' => 'sophie-wachtwoord']);
    }

    public function test_logins_logouts_and_failed_attempts_are_logged(): void
    {
        $this->post('/login', ['username' => 'sophie', 'password' => 'fout']);
        $this->post('/login', ['username' => 'sophie', 'password' => 'fout']);
        $this->post('/login', ['username' => 'sophie', 'password' => 'sophie-wachtwoord']);
        $this->post('/logout');

        $entries = ActivityLog::orderBy('id')->get();
        $this->assertSame(['Mislukte inlogpoging', 'Ingelogd', 'Uitgelogd'], $entries->pluck('description')->all());
        $this->assertSame(2, $entries[0]->count);
        $this->assertFalse($entries[0]->succeeded);
        $this->assertSame('sophie', $entries[0]->username);
        $this->assertSame($this->sophie->id, $entries[1]->user_id);
    }

    public function test_changes_are_logged_with_their_subject_and_result_but_never_the_input(): void
    {
        Queue::fake();
        Storage::fake('local');
        $stream = Player::create(['name' => 'Duncan'])->streams()->create(['title' => 'Dag 1', 'source' => 'test', 'started_at' => now(), 'video_path' => 'streams/1/a.m4a']);
        Storage::disk('local')->put('streams/1/a.m4a', 'audio');
        $this->actingAs($this->sophie);

        $this->post("/streams/{$stream->id}/transcribe");
        $this->post("/streams/{$stream->id}/cancel/transcription");
        $this->put('/profile/password', ['current_password' => 'sophie-wachtwoord', 'password' => 'nieuw-geheim-123', 'password_confirmation' => 'nieuw-geheim-123']);
        $this->get('/streams'); // looking is not logged

        $entries = ActivityLog::orderBy('id')->get();
        $this->assertSame(['Transcriptie gestart', 'Transcriptie geannuleerd', 'Wachtwoord gewijzigd'], $entries->pluck('description')->all());
        $this->assertSame(['stream', $stream->id, 'Dag 1'], [$entries[0]->subject_type, $entries[0]->subject_id, $entries[0]->subject_label]);
        $this->assertSame('Transcriptie toegevoegd aan de wachtrij.', $entries[0]->result);
        $this->assertStringNotContainsString('nieuw-geheim', ActivityLog::all()->toJson());
    }

    public function test_a_refused_action_is_logged_as_failed_and_invalid_input_not_at_all(): void
    {
        $stream = Player::create(['name' => 'Duncan'])->streams()->create(['title' => 'Dag 1', 'source' => 'test', 'started_at' => now()]);
        $this->actingAs($this->sophie);

        $this->post("/streams/{$stream->id}/transcribe");
        $this->post('/players', ['name' => '']);

        $entry = ActivityLog::sole();
        $this->assertFalse($entry->succeeded);
        $this->assertSame('Deze stream heeft geen videobestand.', $entry->result);
    }

    public function test_a_deleted_subject_or_account_still_reads_well(): void
    {
        $player = Player::create(['name' => 'Duncan']);
        $this->actingAs($this->admin)->delete("/players/{$player->id}");
        $this->delete("/users/{$this->sophie->id}");

        $entries = ActivityLog::orderBy('id')->get();
        $this->assertSame(['Speler verwijderd', 'Duncan'], [$entries[0]->description, $entries[0]->subject_label]);
        $this->assertSame(['Account verwijderd', 'sophie'], [$entries[1]->description, $entries[1]->subject_label]);
    }

    public function test_only_the_admin_reads_the_log(): void
    {
        $this->actingAs($this->sophie)->post('/logout');
        ActivityLog::create(['username' => 'sophie', 'action' => 'auth.login', 'description' => 'Ingelogd']);

        $this->actingAs($this->sophie)->get('/activity')->assertForbidden();
        $this->actingAs($this->admin)->get('/activity?user=sophie')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Activity')
            ->where('filter.user', 'sophie')
            ->where('entries.0.username', 'sophie')
            ->where('usernames', ['sophie']));
    }
}
