<?php

namespace Tests\Feature;

use App\Jobs\ExtractStreamEventsJob;
use App\Models\Event;
use App\Models\Player;
use App\Models\Stream;
use App\Models\TranscriptSegment;
use App\Services\EventExtractionWorker;
use App\Services\InvalidModelOutputException;
use App\Services\WorkerPool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class EventExtractionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->onlineWorker();
    }

    public function test_event_extraction_requires_a_completed_transcript(): void
    {
        Queue::fake();
        $stream = $this->createStream('pending');

        $this->post('/streams/'.$stream->id.'/extract-events')
            ->assertRedirect('/streams')
            ->assertSessionHas('error');

        Queue::assertNothingPushed();
    }

    public function test_extraction_is_refused_while_a_retranscription_is_running(): void
    {
        Queue::fake();
        $stream = $this->createStream('processing');
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '1.000', 'end_time' => '3.000', 'text' => 'Old transcript']);

        $this->post('/streams/'.$stream->id.'/extract-events')->assertSessionHas('error');

        Queue::assertNothingPushed();
    }

    public function test_retranscription_is_refused_while_an_analysis_is_running(): void
    {
        Queue::fake();
        Storage::fake('local');
        $stream = $this->createStream('completed');
        $stream->update(['video_path' => 'streams/1/video/stream.mp4', 'event_extraction_status' => 'processing']);
        Storage::disk('local')->put($stream->video_path, 'video');

        $this->post('/streams/'.$stream->id.'/transcribe')->assertSessionHas('error');

        Queue::assertNothingPushed();
        $this->assertSame('completed', $stream->refresh()->transcription_status);
    }

    public function test_extract_endpoint_dispatches_job_for_completed_transcript(): void
    {
        Queue::fake();
        $stream = $this->createStream('completed');
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '1.000', 'end_time' => '3.000', 'text' => 'Alex is near the village']);

        $this->post('/streams/'.$stream->id.'/extract-events')
            ->assertRedirect('/streams')
            ->assertSessionHas('success');

        Queue::assertPushed(ExtractStreamEventsJob::class, fn (ExtractStreamEventsJob $job) => $job->streamId === $stream->id);
        $this->assertSame('queued', $stream->refresh()->event_extraction_status);
    }

    public function test_queued_or_processing_extraction_is_not_dispatched_again(): void
    {
        Queue::fake();
        $stream = $this->createStream('completed');
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '1.000', 'end_time' => '3.000', 'text' => 'Transcript']);

        foreach (['queued', 'processing'] as $status) {
            $stream->update(['event_extraction_status' => $status]);
            $this->post('/streams/'.$stream->id.'/extract-events')
                ->assertRedirect('/streams')
                ->assertSessionHas('error');
            $this->assertSame($status, $stream->refresh()->event_extraction_status);
        }

        Queue::assertNothingPushed();
    }

    public function test_status_endpoint_reports_event_extraction_status(): void
    {
        $stream = $this->createStream('completed');
        $stream->update(['event_extraction_status' => 'failed', 'event_extraction_error' => 'Worker down']);

        $this->getJson('/streams/'.$stream->id.'/transcription-status')
            ->assertOk()
            ->assertJson(['event_extraction_status' => 'failed', 'event_extraction_error' => 'Worker down']);
    }

    public function test_queued_job_moves_to_processing_and_completes(): void
    {
        $stream = $this->createStream('completed');
        $stream->update(['event_extraction_status' => 'queued']);
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '1.000', 'end_time' => '3.000', 'text' => 'Transcript']);
        $worker = Mockery::mock(EventExtractionWorker::class);
        $worker->shouldReceive('extract')->once()->andReturnUsing(function () use ($stream): array {
            $this->assertSame('processing', $stream->refresh()->event_extraction_status);
            return [];
        });

        (new ExtractStreamEventsJob($stream->id))->handle($worker, app(WorkerPool::class));

        $stream->refresh();
        $this->assertSame('completed', $stream->event_extraction_status);
        $this->assertNotNull($stream->event_extraction_started_at);
        $this->assertNotNull($stream->event_extraction_completed_at);
    }

    public function test_job_stores_events_and_links_the_chunk_segments(): void
    {
        $stream = $this->createStream('completed');
        $first = TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '1.000', 'end_time' => '3.000', 'text' => 'Alex is near the village']);
        $second = TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '4.000', 'end_time' => '6.000', 'text' => 'We talk to Alex']);
        $worker = Mockery::mock(EventExtractionWorker::class);
        $worker->shouldReceive('extract')->once()->andReturn([[
            'type' => 'player_encounter',
            'title' => 'Player encounters Alex',
            'description' => 'The transcript says Alex is near the village.',
            'start_time' => 1.0,
            'end_time' => 6.0,
            'confidence' => 0.94,
            'segment_indexes' => [0, 1],
        ]]);

        (new ExtractStreamEventsJob($stream->id))->handle($worker, app(WorkerPool::class));

        $stream->refresh();
        $event = Event::firstOrFail();
        $this->assertSame('completed', $stream->event_extraction_status);
        $this->assertSame('player_encounter', $event->type);
        $this->assertSame(0.94, $event->confidence);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $event->transcriptSegments()->pluck('transcript_segments.id')->all());
    }

    public function test_invalid_worker_event_is_skipped_and_valid_events_are_kept(): void
    {
        $stream = $this->createStream('completed');
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '1.000', 'end_time' => '3.000', 'text' => 'Transcript']);
        $worker = Mockery::mock(EventExtractionWorker::class);
        $worker->shouldReceive('extract')->once()->andReturn([
            ['type' => 'not_allowed', 'title' => 'Bad', 'description' => 'Bad', 'confidence' => 1, 'segment_indexes' => [0]],
            ['type' => 'death', 'title' => 'Bad confidence', 'description' => 'Bad', 'confidence' => '0.5', 'segment_indexes' => [0]],
            'not an object',
            ['type' => 'death', 'title' => 'Creeper', 'description' => 'Killed by a creeper.', 'confidence' => 0.8, 'segment_indexes' => [0]],
        ]);

        (new ExtractStreamEventsJob($stream->id))->handle($worker, app(WorkerPool::class));

        $stream->refresh();
        $this->assertSame('completed', $stream->event_extraction_status);
        $this->assertNull($stream->event_extraction_error);
        $this->assertSame(['Creeper'], Event::pluck('title')->all());
    }

    public function test_processing_stream_is_not_processed_twice(): void
    {
        $stream = $this->createStream('processing');
        $stream->update(['event_extraction_status' => 'processing']);
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '1.000', 'end_time' => '3.000', 'text' => 'Transcript']);
        $worker = Mockery::mock(EventExtractionWorker::class);
        $worker->shouldNotReceive('extract');

        (new ExtractStreamEventsJob($stream->id))->handle($worker, app(WorkerPool::class));

        $this->assertDatabaseCount('events', 0);
    }

    public function test_segments_of_another_stream_are_never_sent_or_linked(): void
    {
        $stream = $this->createStream('completed');
        $other = $this->createStream('completed');
        TranscriptSegment::create(['stream_id' => $other->id, 'start_time' => '0.500', 'end_time' => '2.000', 'text' => 'Other stream line']);
        $own = TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '1.000', 'end_time' => '3.000', 'text' => 'Own stream line']);
        TranscriptSegment::create(['stream_id' => $other->id, 'start_time' => '2.500', 'end_time' => '4.000', 'text' => 'Other stream line 2']);
        $worker = Mockery::mock(EventExtractionWorker::class);
        $worker->shouldReceive('extract')->once()->andReturnUsing(function (\App\Models\Worker $claimed, array $segments): array {
            $this->assertSame(['Own stream line'], array_column($segments, 'text'));
            return [[
                'type' => 'statement', 'title' => 'Own line', 'description' => 'A statement.',
                'start_time' => 1.0, 'end_time' => 3.0, 'confidence' => 0.7, 'segment_indexes' => [0],
            ]];
        });

        (new ExtractStreamEventsJob($stream->id))->handle($worker, app(WorkerPool::class));

        $event = Event::firstOrFail();
        $this->assertSame($stream->id, $event->stream_id);
        $this->assertSame([$own->id], $event->transcriptSegments()->pluck('transcript_segments.id')->all());
    }

    public function test_segment_index_outside_the_chunk_is_rejected(): void
    {
        $stream = $this->createStream('completed');
        $other = $this->createStream('completed');
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '1.000', 'end_time' => '3.000', 'text' => 'Own stream line']);
        TranscriptSegment::create(['stream_id' => $other->id, 'start_time' => '1.000', 'end_time' => '3.000', 'text' => 'Other stream line']);
        $worker = Mockery::mock(EventExtractionWorker::class);
        $worker->shouldReceive('extract')->once()->andReturn([[
            'type' => 'statement', 'title' => 'Bad index', 'description' => 'Points past the chunk.',
            'confidence' => 0.7, 'segment_indexes' => [0, 1],
        ]]);

        (new ExtractStreamEventsJob($stream->id))->handle($worker, app(WorkerPool::class));

        $this->assertSame('completed', $stream->refresh()->event_extraction_status);
        $this->assertDatabaseCount('events', 0);
        $this->assertDatabaseCount('event_transcript_segment', 0);
    }

    public function test_event_timestamps_come_from_the_linked_segments_not_the_model(): void
    {
        $stream = $this->createStream('completed');
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '10.000', 'end_time' => '12.500', 'text' => 'One']);
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '13.000', 'end_time' => '16.250', 'text' => 'Two']);
        $worker = Mockery::mock(EventExtractionWorker::class);
        $worker->shouldReceive('extract')->once()->andReturn([[
            'type' => 'combat', 'title' => 'Fight', 'description' => 'A fight.',
            'start_time' => 999.0, 'end_time' => 2.0, 'confidence' => 0.6, 'segment_indexes' => [1, 0, 1],
        ]]);

        (new ExtractStreamEventsJob($stream->id))->handle($worker, app(WorkerPool::class));

        $event = Event::firstOrFail();
        $this->assertSame('10.000', $event->start_time);
        $this->assertSame('16.250', $event->end_time);
        $this->assertSame(2, $event->transcriptSegments()->count());
    }

    public function test_same_event_found_in_overlapping_chunks_is_stored_once(): void
    {
        config(['services.event_worker.chunk_seconds' => 10, 'services.event_worker.overlap_seconds' => 5]);
        $stream = $this->createStream('completed');
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '0.000', 'end_time' => '4.000', 'text' => 'Opening']);
        $shared = TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '6.000', 'end_time' => '9.000', 'text' => 'Alex shows up']);
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '11.000', 'end_time' => '14.000', 'text' => 'Closing']);
        $calls = 0;
        $worker = Mockery::mock(EventExtractionWorker::class);
        $worker->shouldReceive('extract')->andReturnUsing(function (\App\Models\Worker $claimed, array $segments) use (&$calls): array {
            $calls++;
            $index = array_search('Alex shows up', array_column($segments, 'text'), true);
            if ($index === false) {
                return [];
            }
            return [[
                'type' => 'player_encounter', 'title' => $calls === 1 ? 'Alex shows up' : 'ALEX SHOWS UP',
                'description' => 'Alex appears.', 'start_time' => 6.0, 'end_time' => 9.0,
                'confidence' => 0.8, 'segment_indexes' => [$index],
            ]];
        });

        (new ExtractStreamEventsJob($stream->id))->handle($worker, app(WorkerPool::class));

        $this->assertGreaterThanOrEqual(2, $calls);
        $this->assertDatabaseCount('events', 1);
        $this->assertSame([$shared->id], Event::firstOrFail()->transcriptSegments()->pluck('transcript_segments.id')->all());
    }

    public function test_event_covering_too_many_segments_is_skipped(): void
    {
        config(['services.event_worker.max_segments' => 2]);
        $stream = $this->createStream('completed');
        foreach ([1, 4, 7] as $start) {
            TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => number_format($start, 3, '.', ''), 'end_time' => number_format($start + 2, 3, '.', ''), 'text' => 'Line '.$start]);
        }
        $worker = Mockery::mock(EventExtractionWorker::class);
        $worker->shouldReceive('extract')->once()->andReturn([
            ['type' => 'statement', 'title' => 'Chunk summary', 'description' => 'Everything.', 'confidence' => 0.9, 'segment_indexes' => [0, 1, 2]],
            ['type' => 'death', 'title' => 'Dies', 'description' => 'Dies.', 'confidence' => 0.9, 'segment_indexes' => [1, 2]],
        ]);

        (new ExtractStreamEventsJob($stream->id))->handle($worker, app(WorkerPool::class));

        $this->assertSame(['Dies'], Event::pluck('title')->all());
    }

    public function test_same_moment_with_different_title_and_type_keeps_the_most_confident_event(): void
    {
        config(['services.event_worker.chunk_seconds' => 10, 'services.event_worker.overlap_seconds' => 5]);
        $stream = $this->createStream('completed');
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '0.000', 'end_time' => '4.000', 'text' => 'Opening']);
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '6.000', 'end_time' => '7.000', 'text' => 'Sam shows up']);
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '7.500', 'end_time' => '9.000', 'text' => 'Hoi Sam']);
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '11.000', 'end_time' => '14.000', 'text' => 'Closing']);
        $calls = 0;
        $worker = Mockery::mock(EventExtractionWorker::class);
        $worker->shouldReceive('extract')->andReturnUsing(function (\App\Models\Worker $claimed, array $segments) use (&$calls): array {
            $calls++;
            $texts = array_column($segments, 'text');
            $first = array_search('Sam shows up', $texts, true);
            $second = array_search('Hoi Sam', $texts, true);
            if ($first === false || $second === false) {
                return [];
            }
            return $calls === 1
                ? [['type' => 'other', 'title' => 'Sam arrives', 'description' => 'Sam is there.', 'confidence' => 0.6, 'segment_indexes' => [$first, $second]]]
                : [['type' => 'player_encounter', 'title' => 'Meets Sam', 'description' => 'Meets Sam.', 'confidence' => 0.9, 'segment_indexes' => [$second]]];
        });

        (new ExtractStreamEventsJob($stream->id))->handle($worker, app(WorkerPool::class));

        $this->assertGreaterThanOrEqual(2, $calls);
        $this->assertSame(['Meets Sam'], Event::pluck('title')->all());
    }

    public function test_worker_crash_marks_extraction_as_failed_with_error(): void
    {
        Http::fake(['worker:8001/extract-events' => Http::response(['error' => 'Worker error: CUDA out of memory'], 500)]);
        $stream = $this->createStream('completed');
        $stream->update(['event_extraction_status' => 'queued']);
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '1.000', 'end_time' => '3.000', 'text' => 'Transcript']);

        try {
            (new ExtractStreamEventsJob($stream->id))->handle(app(EventExtractionWorker::class), app(WorkerPool::class));
            $this->fail('The job should rethrow the worker failure so the queue can retry it.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Worker error: CUDA out of memory', $exception->getMessage());
        }

        $stream->refresh();
        $this->assertSame('failed', $stream->event_extraction_status);
        $this->assertSame('Worker error: CUDA out of memory', $stream->event_extraction_error);
        $this->assertDatabaseCount('events', 0);
    }

    public function test_chunk_with_unusable_model_output_is_skipped_and_the_rest_is_stored(): void
    {
        config(['services.event_worker.chunk_seconds' => 10, 'services.event_worker.overlap_seconds' => 0]);
        $stream = $this->createStream('completed');
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '0.000', 'end_time' => '4.000', 'text' => 'First chunk']);
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '12.000', 'end_time' => '15.000', 'text' => 'Second chunk']);
        Http::fake(['worker:8001/extract-events' => Http::sequence()
            ->push(['error' => 'Event model returned invalid JSON'], 422)
            ->push(['events' => [['type' => 'statement', 'title' => 'Second', 'description' => 'Said something.', 'confidence' => 0.7, 'segment_indexes' => [0]]]])]);

        (new ExtractStreamEventsJob($stream->id))->handle(app(EventExtractionWorker::class), app(WorkerPool::class));

        $stream->refresh();
        $this->assertSame('completed', $stream->event_extraction_status);
        $this->assertStringContainsString('1 chunk(s) overgeslagen', $stream->event_extraction_error);
        $this->assertStringContainsString('Event model returned invalid JSON', $stream->event_extraction_error);
        $this->assertSame(['Second'], Event::pluck('title')->all());
    }

    public function test_rerun_replaces_previous_events_and_a_failed_run_keeps_them(): void
    {
        $stream = $this->createStream('completed');
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '1.000', 'end_time' => '3.000', 'text' => 'Transcript']);
        $event = fn (string $title) => ['type' => 'statement', 'title' => $title, 'description' => 'Something.', 'confidence' => 0.7, 'segment_indexes' => [0]];
        $worker = Mockery::mock(EventExtractionWorker::class);
        $worker->shouldReceive('extract')->once()->andReturn([$event('First run')]);
        $worker->shouldReceive('extract')->once()->andReturn([$event('Second run')]);
        $worker->shouldReceive('extract')->once()->andThrow(new \RuntimeException('The event extraction worker could not be reached'));

        // Like the Analyseren button, every run is queued first.
        $queue = fn () => $stream->newQuery()->whereKey($stream->id)->update(['event_extraction_status' => 'queued']);
        $queue();
        (new ExtractStreamEventsJob($stream->id))->handle($worker, app(WorkerPool::class));
        $queue();
        (new ExtractStreamEventsJob($stream->id))->handle($worker, app(WorkerPool::class));
        $this->assertSame(['Second run'], Event::pluck('title')->all());

        try {
            $queue();
            (new ExtractStreamEventsJob($stream->id))->handle($worker, app(WorkerPool::class));
        } catch (\RuntimeException) {
        }
        $this->assertSame('failed', $stream->refresh()->event_extraction_status);
        $this->assertSame(['Second run'], Event::pluck('title')->all());
    }

    public function test_unreachable_worker_and_final_failure_are_reported(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused'));
        $stream = $this->createStream('completed');
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '1.000', 'end_time' => '3.000', 'text' => 'Transcript']);
        $job = new ExtractStreamEventsJob($stream->id);

        try {
            $job->handle(app(EventExtractionWorker::class), app(WorkerPool::class));
        } catch (\RuntimeException) {
        }
        $this->assertStringContainsString('niet bereikbaar', (string) $stream->refresh()->event_extraction_error);

        $stream->update(['event_extraction_status' => 'processing', 'event_extraction_error' => null]);
        $job->failed(new \RuntimeException('Job timed out'));
        $stream->refresh();
        $this->assertSame('failed', $stream->event_extraction_status);
        $this->assertSame('Job timed out', $stream->event_extraction_error);
    }

    public function test_progress_is_saved_after_every_chunk_including_skipped_ones(): void
    {
        config(['services.event_worker.chunk_seconds' => 10, 'services.event_worker.overlap_seconds' => 0]);
        $stream = $this->createStream('completed');
        foreach ([0, 12, 24] as $start) {
            TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => $start.'.000', 'end_time' => ($start + 3).'.000', 'text' => 'Chunk at '.$start]);
        }
        $seen = [];
        $worker = Mockery::mock(EventExtractionWorker::class);
        $worker->shouldReceive('extract')->times(3)->andReturnUsing(function () use ($stream, &$seen): array {
            $stream->refresh();
            $seen[] = [$stream->event_extraction_chunks_done, $stream->event_extraction_chunks_total];
            if (count($seen) === 2) {
                throw new InvalidModelOutputException('Event model returned invalid JSON');
            }
            return [];
        });

        (new ExtractStreamEventsJob($stream->id))->handle($worker, app(WorkerPool::class));

        $this->assertSame([[0, 3], [1, 3], [2, 3]], $seen);
        $stream->refresh();
        $this->assertSame(3, $stream->event_extraction_chunks_done);
        $this->assertSame(100, $stream->eventExtractionProgress());
    }

    public function test_status_endpoint_reports_analysis_progress_and_eta(): void
    {
        $this->travelTo(now()->startOfSecond());
        $stream = $this->createStream('completed');
        $stream->forceFill([
            'event_extraction_status' => 'processing',
            'event_extraction_started_at' => now()->subSeconds(100),
            'event_extraction_chunks_done' => 10,
            'event_extraction_chunks_total' => 40,
        ])->save();

        $this->getJson('/streams/'.$stream->id.'/transcription-status')
            ->assertOk()
            ->assertJson([
                'event_extraction_progress' => 25,
                'event_extraction_chunks_done' => 10,
                'event_extraction_chunks_total' => 40,
                'event_extraction_eta_seconds' => 300,
            ]);
    }

    private function createStream(string $transcriptionStatus): Stream
    {
        $player = Player::create(['name' => 'Player '.uniqid()]);
        return Stream::create([
            'player_id' => $player->id,
            'title' => 'Event test stream',
            'started_at' => '2026-10-03 19:00:00+00',
            'ended_at' => '2026-10-03 20:00:00+00',
            'source' => 'test',
            'transcription_status' => $transcriptionStatus,
        ]);
    }
}
