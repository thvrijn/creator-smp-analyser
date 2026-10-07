<?php

namespace Tests;

use App\Models\Stream;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Every page needs a login, so tests act as a logged-in user unless a test class turns this off. */
    protected bool $signedIn = true;

    protected function setUp(): void
    {
        parent::setUp();

        if ($this->signedIn) {
            // Not saved: the session guard only needs a user object, and not every test refreshes the database.
            $this->actingAs(new User(['username' => 'test', 'name' => 'Test']));
        }
    }

    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

        return $app;
    }

    /** A worker that just checked in and can do both tasks, reachable as http://worker:8001. */
    protected function onlineWorker(array $attributes = []): Worker
    {
        return Worker::create([
            'name' => 'test-worker',
            'url' => 'http://worker:8001',
            'backend' => 'cuda',
            'capabilities' => Worker::TASKS,
            'last_seen_at' => now(),
            ...$attributes,
        ]);
    }

    /** Like the buttons do before dispatching: a job only starts from a queued status (Stream::STARTABLE_STATUSES). */
    protected function markQueued(Stream $stream, string $task): Stream
    {
        Stream::query()->whereKey($stream->id)->update(["{$task}_status" => 'queued']);

        return $stream;
    }
}
