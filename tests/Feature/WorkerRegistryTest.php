<?php

namespace Tests\Feature;

use App\Jobs\ExtractStreamEventsJob;
use App\Jobs\TranscribeStreamJob;
use App\Models\Player;
use App\Models\Stream;
use App\Models\Worker;
use App\Services\EventExtractionWorker;
use App\Services\TranscriptionWorker;
use App\Services\WorkerPool;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class WorkerRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        config(['services.workers.token' => 'secret', 'services.workers.app_url' => 'http://pi.tailnet:8000']);
    }

    private function heartbeat(array $data = [], string $token = 'secret')
    {
        return $this->withToken($token)->postJson('/api/workers/heartbeat', [
            'name' => 'pc-4080',
            'url' => 'http://pc:8001',
            'backend' => 'cuda',
            'gpu_name' => 'NVIDIA GeForce RTX 4080',
            'vram_mb' => 16376,
            'capabilities' => ['transcribe', 'extract'],
            'priority' => 100,
            'loaded_model' => null,
            'busy' => false,
            ...$data,
        ]);
    }

    public function test_a_heartbeat_registers_the_worker_as_online(): void
    {
        $this->heartbeat()->assertOk();

        $worker = Worker::sole();
        $this->assertSame(['pc-4080', 'http://pc:8001', 16376], [$worker->name, $worker->url, $worker->vram_mb]);
        $this->assertTrue($worker->isOnline());

        $this->travel(46)->seconds();
        $this->assertFalse($worker->refresh()->isOnline());
        $this->heartbeat(['url' => 'http://pc.tailnet:8001'])->assertOk();
        $this->assertSame(1, Worker::count());
        $this->assertSame('http://pc.tailnet:8001', $worker->refresh()->url);
        $this->assertTrue($worker->isOnline());
    }

    public function test_a_heartbeat_needs_the_token(): void
    {
        $this->heartbeat(token: 'wrong')->assertUnauthorized();
        config(['services.workers.token' => null]);
        $this->heartbeat(token: '')->assertUnauthorized();
        $this->assertSame(0, Worker::count());
    }

    public function test_signing_off_makes_the_worker_offline(): void
    {
        $this->heartbeat();
        $this->withToken('secret')->postJson('/api/workers/offline', ['name' => 'pc-4080'])->assertOk();

        $this->assertFalse(Worker::sole()->isOnline());
    }

    public function test_an_idle_worker_with_an_old_claim_is_released_by_its_heartbeat(): void
    {
        $stream = $this->stream();
        $worker = $this->onlineWorker(['name' => 'pc-4080']);
        app(WorkerPool::class)->claim('transcribe', $stream);

        $this->heartbeat(['busy' => false]);
        $this->assertSame('transcribe', $worker->refresh()->current_task);

        $this->travel(WorkerPool::STALE_CLAIM_SECONDS + 1)->seconds();
        $this->heartbeat(['busy' => true]);
        $this->assertSame('transcribe', $worker->refresh()->current_task);
        $this->heartbeat(['busy' => false]);
        $this->assertNull($worker->refresh()->current_task);
    }

    public function test_the_best_free_online_enabled_worker_with_the_capability_is_claimed(): void
    {
        $stream = $this->stream();
        $pool = app(WorkerPool::class);
        $this->onlineWorker(['name' => 'mac', 'url' => 'http://mac:8001', 'backend' => 'mlx', 'priority' => 50]);
        $this->onlineWorker(['name' => 'laptop', 'url' => 'http://laptop:8001', 'priority' => 100, 'vram_mb' => 8151]);
        $this->onlineWorker(['name' => 'pc', 'url' => 'http://pc:8001', 'priority' => 100, 'vram_mb' => 16376]);
        $this->onlineWorker(['name' => 'offline', 'priority' => 500, 'last_seen_at' => now()->subMinutes(5)]);
        $this->onlineWorker(['name' => 'disabled', 'priority' => 500, 'enabled' => false]);
        $this->onlineWorker(['name' => 'extract-only', 'priority' => 400, 'capabilities' => ['extract']]);

        $this->assertSame('pc', $pool->claim('transcribe', $stream)?->name);
        $this->assertSame('laptop', $pool->claim('transcribe', $stream)?->name);
        $this->assertSame('mac', $pool->claim('transcribe', $stream)?->name);
        $this->assertNull($pool->claim('transcribe', $stream));
        $this->assertSame('extract-only', $pool->claim('extract', $stream)?->name);

        $pool->release(Worker::where('name', 'pc')->sole());
        $this->assertSame('pc', $pool->claim('transcribe', $stream)?->name);
    }

    public function test_without_a_free_worker_the_job_waits_and_touches_the_stream(): void
    {
        $stream = $this->stream(['transcription_status' => 'queued', 'transcription_stage' => 'queued']);
        $transcriber = Mockery::mock(TranscriptionWorker::class);
        $transcriber->shouldNotReceive('transcribe');

        (new TranscribeStreamJob($stream->id))->handle($transcriber, app(WorkerPool::class), app(\App\Services\StreamJobCanceller::class));
        $this->assertSame(['waiting', 'waiting_for_worker'], [$stream->refresh()->transcription_status, $stream->transcription_stage]);

        $this->travel(11)->minutes();
        $this->assertTrue($stream->isStalled('transcription_status'));
        (new TranscribeStreamJob($stream->id))->handle($transcriber, app(WorkerPool::class), app(\App\Services\StreamJobCanceller::class));
        $this->assertFalse($stream->refresh()->isStalled('transcription_status'));
        $this->assertSame('waiting', $stream->transcription_status);

        $extractor = Mockery::mock(EventExtractionWorker::class);
        $extractor->shouldNotReceive('extract');
        $stream->update(['transcription_status' => 'completed', 'event_extraction_status' => 'queued']);
        (new ExtractStreamEventsJob($stream->id))->handle($extractor, app(WorkerPool::class), app(\App\Services\StreamJobCanceller::class));
        $this->assertSame('waiting', $stream->refresh()->event_extraction_status);
    }

    public function test_a_waiting_job_is_released_back_onto_the_queue(): void
    {
        $stream = $this->stream(['transcription_status' => 'queued']);
        $job = new TranscribeStreamJob($stream->id);
        $queueJob = Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
        $queueJob->shouldReceive('release')->once()->with(30);
        $job->setJob($queueJob);

        $job->handle(app(TranscriptionWorker::class), app(WorkerPool::class), app(\App\Services\StreamJobCanceller::class));

        $this->assertGreaterThan(now()->addDays(6), $job->retryUntil());
    }

    public function test_a_waiting_stream_counts_as_active_and_can_be_restarted_when_stalled(): void
    {
        Queue::fake();
        $stream = $this->stream(['transcription_status' => 'waiting']);

        $this->post("/streams/{$stream->id}/transcribe")->assertSessionHas('error');
        Queue::assertNothingPushed();

        $this->travel(11)->minutes();
        $this->post("/streams/{$stream->id}/transcribe")->assertSessionHas('success');
        Queue::assertPushed(TranscribeStreamJob::class);
    }

    public function test_the_worker_gets_the_token_a_signed_audio_url_and_is_released_afterwards(): void
    {
        $stream = $this->stream(['transcription_status' => 'queued']);
        $worker = $this->onlineWorker(['url' => 'http://pc:8001/']);
        Http::fake(['pc:8001/transcribe' => Http::response(json_encode(['type' => 'segment', 'start' => 1, 'end' => 2, 'text' => 'Hallo'])."\n")]);

        (new TranscribeStreamJob($stream->id))->handle(app(TranscriptionWorker::class), app(WorkerPool::class), app(\App\Services\StreamJobCanceller::class));

        $this->assertSame('completed', $stream->refresh()->transcription_status);
        $this->assertNull($worker->refresh()->current_task);
        Http::assertSent(function (Request $request) use ($stream): bool {
            return $request->url() === 'http://pc:8001/transcribe'
                && $request->hasHeader('Authorization', 'Bearer secret')
                && $request['video_path'] === $stream->video_path
                && str_starts_with($request['video_url'], "http://pi.tailnet:8000/api/worker-files/streams/{$stream->id}?expires=");
        });
    }

    public function test_an_unreachable_worker_is_marked_offline_so_a_retry_picks_another(): void
    {
        $stream = $this->stream(['transcription_status' => 'queued']);
        $worker = $this->onlineWorker();
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused'));

        try {
            (new TranscribeStreamJob($stream->id))->handle(app(TranscriptionWorker::class), app(WorkerPool::class), app(\App\Services\StreamJobCanceller::class));
            $this->fail('The job should fail so the queue retries it.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('test-worker is niet bereikbaar', $exception->getMessage());
        }

        $worker->refresh();
        $this->assertFalse($worker->isOnline());
        $this->assertNull($worker->current_task);
    }

    public function test_the_signed_audio_url_serves_the_file_and_rejects_tampering_or_expiry(): void
    {
        $stream = $this->stream();
        $url = app(WorkerPool::class)->fileUrl($stream);
        $path = substr($url, strlen('http://pi.tailnet:8000'));

        // Signed relative to the path, so the host the worker uses does not matter.
        $this->get($path)->assertOk()->assertHeader('Content-Type', 'audio/mp4');
        $this->assertSame('audio', file_get_contents($this->get($path)->baseResponse->getFile()->getPathname()));
        $other = $this->stream();
        $this->get(str_replace("streams/{$stream->id}?", "streams/{$other->id}?", $path))->assertForbidden();

        $this->travel(13)->hours();
        $this->get($path)->assertForbidden();
    }

    public function test_settings_lists_the_workers_and_a_worker_can_be_disabled_and_forgotten(): void
    {
        $online = $this->onlineWorker(['name' => 'laptop']);
        $offline = $this->onlineWorker(['name' => 'mac', 'last_seen_at' => now()->subHour()]);

        $this->get('/settings')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Settings')
            ->has('workers', 2)
            ->where('workers.0.name', 'laptop')
            ->where('workers.0.online', true)
            ->where('workers.1.online', false));

        $this->put("/workers/{$online->id}", ['enabled' => false])->assertSessionHas('success');
        $this->assertFalse($online->refresh()->enabled);

        $this->delete("/workers/{$online->id}")->assertSessionHas('error');
        $this->delete("/workers/{$offline->id}")->assertSessionHas('success');
        $this->assertSame(['laptop'], Worker::pluck('name')->all());
    }

    private function stream(array $attributes = []): Stream
    {
        Storage::fake('local');
        $player = Player::create(['name' => 'Player '.uniqid()]);
        $stream = Stream::create([
            'player_id' => $player->id,
            'title' => 'Worker test stream',
            'source' => '',
            'started_at' => now(),
            'video_path' => 'streams/1/audio.m4a',
            'video_mime_type' => 'audio/mp4',
            ...$attributes,
        ]);
        Storage::disk('local')->put($stream->video_path, 'audio');

        return $stream;
    }
}
