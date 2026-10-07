<?php

namespace Tests;

use App\Models\Worker;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
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
}
