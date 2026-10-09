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
        $worker = $this->workerMock();
        $worker->shouldReceive('extract')->once()->andReturnUsing(function () use ($stream): array {
            $this->assertSame('processing', $stream->refresh()->event_extraction_status);
            return $this->chunkResult([]);
        });

        $this->markQueued($stream, 'event_extraction');

        (new ExtractStreamEventsJob($stream->id))->handle($worker, app(WorkerPool::class), app(\App\Services\StreamJobCanceller::class));

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
        $worker = $this->workerMock();
        $worker->shouldReceive('extract')->once()->andReturn($this->chunkResult([[
            'type' => 'player_encounter',
            'title' => 'Player encounters Alex',
            'description' => 'The transcript says Alex is near the village.',
            'start_time' => 1.0,
            'end_time' => 6.0,
            'confidence' => 0.94,
            'segment_indexes' => [0, 1],
        ]]));

        $this->markQueued($stream, 'event_extraction');

        (new ExtractStreamEventsJob($stream->id))->handle($worker, app(WorkerPool::class), app(\App\Services\StreamJobCanceller::class));

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
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '4.000', 'end_time' => '6.000', 'text' => 'More transcript']);
        $worker = $this->workerMock();
        $worker->shouldReceive('extract')->once()->andReturn($this->chunkResult([
            ['type' => 'not_allowed', 'title' => 'Bad', 'description' => 'Bad', 'confidence' => 1, 'segment_indexes' => [0, 1]],
            // Types of the old prompt are no longer accepted.
            ['type' => 'statement', 'title' => 'Old type', 'description' => 'Bad', 'confidence' => 1, 'segment_indexes' => [0, 1]],
            ['type' => 'death', 'title' => 'Bad confidence', 'description' => 'Bad', 'confidence' => '0.5', 'segment_indexes' => [0, 1]],
            // One line is a remark, not an event.
            ['type' => 'death', 'title' => 'One remark', 'description' => 'Bad', 'confidence' => 0.8, 'segment_indexes' => [1]],
            'not an object',
            ['type' => 'death', 'title' => 'Creeper', 'description' => 'Killed by a creeper.', 'confidence' => 0.8, 'segment_indexes' => [0, 1]],
        ]));

        $this->markQueued($stream, 'event_extraction');

        (new ExtractStreamEventsJob($stream->id))->handle($worker, app(WorkerPool::class), app(\App\Services\StreamJobCanceller::class));

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
        $worker = $this->workerMock();
        $worker->shouldNotReceive('extract');


        (new ExtractStreamEventsJob($stream->id))->handle($worker, app(WorkerPool::class), app(\App\Services\StreamJobCanceller::class));

        $this->assertDatabaseCount('events', 0);
    }

    public function test_segments_of_another_stream_are_never_sent_or_linked(): void
    {
        $stream = $this->createStream('completed');
        $other = $this->createStream('completed');
        TranscriptSegment::create(['stream_id' => $other->id, 'start_time' => '0.500', 'end_time' => '2.000', 'text' => 'Other stream line']);
        $own = TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '1.000', 'end_time' => '3.000', 'text' => 'Own stream line']);
        TranscriptSegment::create(['stream_id' => $other->id, 'start_time' => '2.500', 'end_time' => '4.000', 'text' => 'Other stream line 2']);
        $ownSecond = TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '4.500', 'end_time' => '5.000', 'text' => 'Own stream line 2']);
        $worker = $this->workerMock();
        $worker->shouldReceive('extract')->once()->andReturnUsing(function (\App\Models\Worker $claimed, array $segments): array {
            $this->assertSame(['Own stream line', 'Own stream line 2'], array_column($segments, 'text'));
            return $this->chunkResult([[
                'type' => 'plot', 'title' => 'Own line', 'description' => 'A plan.',
                'start_time' => 1.0, 'end_time' => 3.0, 'confidence' => 0.7, 'segment_indexes' => [0, 1],
            ]]);
        });

        $this->markQueued($stream, 'event_extraction');

        (new ExtractStreamEventsJob($stream->id))->handle($worker, app(WorkerPool::class), app(\App\Services\StreamJobCanceller::class));

        $event = Event::firstOrFail();
        $this->assertSame($stream->id, $event->stream_id);
        $this->assertEqualsCanonicalizing([$own->id, $ownSecond->id], $event->transcriptSegments()->pluck('transcript_segments.id')->all());
    }

    public function test_segment_index_outside_the_chunk_is_rejected(): void
    {
        $stream = $this->createStream('completed');
        $other = $this->createStream('completed');
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '1.000', 'end_time' => '3.000', 'text' => 'Own stream line']);
        TranscriptSegment::create(['stream_id' => $other->id, 'start_time' => '1.000', 'end_time' => '3.000', 'text' => 'Other stream line']);
        $worker = $this->workerMock();
        $worker->shouldReceive('extract')->once()->andReturn($this->chunkResult([[
            'type' => 'plot', 'title' => 'Bad index', 'description' => 'Points past the chunk.',
            'confidence' => 0.7, 'segment_indexes' => [0, 1],
        ]]));

        $this->markQueued($stream, 'event_extraction');

        (new ExtractStreamEventsJob($stream->id))->handle($worker, app(WorkerPool::class), app(\App\Services\StreamJobCanceller::class));

        $this->assertSame('completed', $stream->refresh()->event_extraction_status);
        $this->assertDatabaseCount('events', 0);
        $this->assertDatabaseCount('event_transcript_segment', 0);
    }

    public function test_event_timestamps_come_from_the_linked_segments_not_the_model(): void
    {
        $stream = $this->createStream('completed');
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '10.000', 'end_time' => '12.500', 'text' => 'One']);
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '13.000', 'end_time' => '16.250', 'text' => 'Two']);
        $worker = $this->workerMock();
        $worker->shouldReceive('extract')->once()->andReturn($this->chunkResult([[
            'type' => 'combat', 'title' => 'Fight', 'description' => 'A fight.',
            'start_time' => 999.0, 'end_time' => 2.0, 'confidence' => 0.6, 'segment_indexes' => [1, 0, 1],
        ]]));

        $this->markQueued($stream, 'event_extraction');

        (new ExtractStreamEventsJob($stream->id))->handle($worker, app(WorkerPool::class), app(\App\Services\StreamJobCanceller::class));

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
        $shared = TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '6.000', 'end_time' => '7.000', 'text' => 'Alex shows up']);
        $hello = TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '7.500', 'end_time' => '9.000', 'text' => 'Hoi Alex']);
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '11.000', 'end_time' => '14.000', 'text' => 'Closing']);
        $calls = 0;
        $worker = $this->workerMock();
        $worker->shouldReceive('extract')->andReturnUsing(function (\App\Models\Worker $claimed, array $segments) use (&$calls): array {
            $calls++;
            $first = array_search('Alex shows up', array_column($segments, 'text'), true);
            $second = array_search('Hoi Alex', array_column($segments, 'text'), true);
            if ($first === false || $second === false) {
                return $this->chunkResult([]);
            }
            return $this->chunkResult([[
                'type' => 'player_encounter', 'title' => $calls === 1 ? 'Alex shows up' : 'ALEX SHOWS UP',
                'description' => 'Alex appears.', 'start_time' => 6.0, 'end_time' => 9.0,
                'confidence' => 0.8, 'segment_indexes' => [$first, $second],
            ]]);
        });

        $this->markQueued($stream, 'event_extraction');

        (new ExtractStreamEventsJob($stream->id))->handle($worker, app(WorkerPool::class), app(\App\Services\StreamJobCanceller::class));

        $this->assertGreaterThanOrEqual(2, $calls);
        $this->assertDatabaseCount('events', 1);
        $this->assertEqualsCanonicalizing([$shared->id, $hello->id], Event::firstOrFail()->transcriptSegments()->pluck('transcript_segments.id')->all());
    }

    public function test_event_covering_too_many_segments_is_skipped(): void
    {
        config(['services.event_worker.max_segments' => 2]);
        $stream = $this->createStream('completed');
        foreach ([1, 4, 7] as $start) {
            TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => number_format($start, 3, '.', ''), 'end_time' => number_format($start + 2, 3, '.', ''), 'text' => 'Line '.$start]);
        }
        $worker = $this->workerMock();
        $worker->shouldReceive('extract')->once()->andReturn($this->chunkResult([
            ['type' => 'plot', 'title' => 'Chunk summary', 'description' => 'Everything.', 'confidence' => 0.9, 'segment_indexes' => [0, 1, 2]],
            ['type' => 'death', 'title' => 'Dies', 'description' => 'Dies.', 'confidence' => 0.9, 'segment_indexes' => [1, 2]],
        ]));

        $this->markQueued($stream, 'event_extraction');

        (new ExtractStreamEventsJob($stream->id))->handle($worker, app(WorkerPool::class), app(\App\Services\StreamJobCanceller::class));

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
        $worker = $this->workerMock();
        $worker->shouldReceive('extract')->andReturnUsing(function (\App\Models\Worker $claimed, array $segments) use (&$calls): array {
            $calls++;
            $texts = array_column($segments, 'text');
            $first = array_search('Sam shows up', $texts, true);
            $second = array_search('Hoi Sam', $texts, true);
            if ($first === false || $second === false) {
                return $this->chunkResult([]);
            }
            return $this->chunkResult($calls === 1
                ? [['type' => 'other', 'title' => 'Sam arrives', 'description' => 'Sam is there.', 'confidence' => 0.6, 'segment_indexes' => [$first, $second]]]
                : [['type' => 'player_encounter', 'title' => 'Meets Sam', 'description' => 'Meets Sam.', 'confidence' => 0.9, 'segment_indexes' => [$second, $first]]]);
        });

        $this->markQueued($stream, 'event_extraction');

        (new ExtractStreamEventsJob($stream->id))->handle($worker, app(WorkerPool::class), app(\App\Services\StreamJobCanceller::class));

        $this->assertGreaterThanOrEqual(2, $calls);
        $this->assertSame(['Meets Sam'], Event::pluck('title')->all());
    }

    public function test_a_happening_reported_again_in_the_next_chunk_becomes_one_event(): void
    {
        config(['services.event_worker.chunk_seconds' => 10, 'services.event_worker.overlap_seconds' => 0]);
        $stream = $this->createStream('completed');
        foreach ([0, 3, 6, 10, 13, 16, 20, 23] as $start) {
            TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => $start, 'end_time' => $start + 2, 'text' => "Zin {$start}"]);
        }
        $worker = $this->workerMock();
        $worker->shouldReceive('extract')->times(3)->andReturn(
            // The district meeting in chunk 1 and 2, told with mostly the same words; chunk 3 is about something else.
            $this->chunkResult([['type' => 'plot', 'title' => 'Wissel tussen Noord en Zuid', 'description' => 'Jeremy vertelt dat vijf spelers van Noord naar Zuid moeten verhuizen.', 'confidence' => 0.8, 'segment_indexes' => [1, 2]]]),
            $this->chunkResult([['type' => 'conversation', 'title' => 'Verhuizing naar Zuid', 'description' => 'Jeremy vertelt dat vijf spelers van Noord naar Zuid moeten verhuizen, Morrog vraagt wie.', 'confidence' => 0.9, 'segment_indexes' => [0, 1]]]),
            $this->chunkResult([['type' => 'combat', 'title' => 'Gevecht met zombies', 'description' => 'Zombies vallen het huisje aan.', 'confidence' => 0.9, 'segment_indexes' => [0, 1]]]),
        );

        $this->markQueued($stream, 'event_extraction');
        (new ExtractStreamEventsJob($stream->id))->handle($worker, app(WorkerPool::class), app(\App\Services\StreamJobCanceller::class));

        $events = Event::orderBy('start_time')->get();
        $this->assertSame(['Wissel tussen Noord en Zuid', 'Gevecht met zombies'], $events->pluck('title')->all());
        $this->assertEquals([3.0, 15.0], [$events[0]->start_time, $events[0]->end_time]);
        $this->assertSame(4, $events[0]->transcriptSegments()->count());
    }

    public function test_an_event_over_the_whole_chunk_is_its_summary_and_skipped(): void
    {
        $this->assertTrue(ExtractStreamEventsJob::coversWholeChunk(170, 173));
        $this->assertFalse(ExtractStreamEventsJob::coversWholeChunk(92, 155));
        // A short last chunk may be one event.
        $this->assertFalse(ExtractStreamEventsJob::coversWholeChunk(10, 10));
    }

    public function test_worker_crash_marks_extraction_as_failed_with_error(): void
    {
        Http::fake(['worker:8001/extract-events' => Http::response(['error' => 'Worker error: CUDA out of memory'], 500)]);
        $stream = $this->createStream('completed');
        $stream->update(['event_extraction_status' => 'queued']);
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '1.000', 'end_time' => '3.000', 'text' => 'Transcript']);

        try {
            (new ExtractStreamEventsJob($stream->id))->handle(app(EventExtractionWorker::class), app(WorkerPool::class), app(\App\Services\StreamJobCanceller::class));
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
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '15.500', 'end_time' => '17.000', 'text' => 'Second chunk, more']);
        Http::fake([
            'worker:8001/extract-events' => Http::sequence()
                ->push(['error' => 'Event model returned invalid JSON'], 422)
                ->push(['summary' => 'Er gebeurt iets.', 'events' => [['type' => 'plot', 'title' => 'Second', 'description' => 'Said something.', 'confidence' => 0.7, 'segment_indexes' => [0, 1]]]]),
            'worker:8001/summarize-story' => Http::response(['summary' => 'Het verhaal.', 'players' => []]),
        ]);

        $this->markQueued($stream, 'event_extraction');

        (new ExtractStreamEventsJob($stream->id))->handle(app(EventExtractionWorker::class), app(WorkerPool::class), app(\App\Services\StreamJobCanceller::class));

        $stream->refresh();
        $this->assertSame('completed', $stream->event_extraction_status);
        $this->assertStringContainsString('1 chunk(s) overgeslagen', $stream->event_extraction_error);
        $this->assertStringContainsString('Event model returned invalid JSON', $stream->event_extraction_error);
        $this->assertSame(['Second'], Event::pluck('title')->all());
        $this->assertSame('Het verhaal.', $stream->story_summary);
    }

    public function test_rerun_replaces_previous_events_and_a_failed_run_keeps_them(): void
    {
        $stream = $this->createStream('completed');
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '1.000', 'end_time' => '3.000', 'text' => 'Transcript']);
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '4.000', 'end_time' => '6.000', 'text' => 'More transcript']);
        $event = fn (string $title) => ['type' => 'plot', 'title' => $title, 'description' => 'Something.', 'confidence' => 0.7, 'segment_indexes' => [0, 1]];
        $worker = $this->workerMock();
        $worker->shouldReceive('extract')->once()->andReturn($this->chunkResult([$event('First run')]));
        $worker->shouldReceive('extract')->once()->andReturn($this->chunkResult([$event('Second run')]));
        $worker->shouldReceive('extract')->once()->andThrow(new \RuntimeException('The event extraction worker could not be reached'));

        // Like the Analyseren button, every run is queued first.
        $queue = fn () => $stream->newQuery()->whereKey($stream->id)->update(['event_extraction_status' => 'queued']);
        $queue();
        (new ExtractStreamEventsJob($stream->id))->handle($worker, app(WorkerPool::class), app(\App\Services\StreamJobCanceller::class));
        $queue();
        (new ExtractStreamEventsJob($stream->id))->handle($worker, app(WorkerPool::class), app(\App\Services\StreamJobCanceller::class));
        $this->assertSame(['Second run'], Event::pluck('title')->all());

        try {
            $queue();
            (new ExtractStreamEventsJob($stream->id))->handle($worker, app(WorkerPool::class), app(\App\Services\StreamJobCanceller::class));
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
        $this->markQueued($stream, 'event_extraction');
        $job = new ExtractStreamEventsJob($stream->id);

        try {
            $job->handle(app(EventExtractionWorker::class), app(WorkerPool::class), app(\App\Services\StreamJobCanceller::class));
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
        $worker = $this->workerMock();
        $worker->shouldReceive('extract')->times(3)->andReturnUsing(function () use ($stream, &$seen): array {
            $stream->refresh();
            $seen[] = [$stream->event_extraction_chunks_done, $stream->event_extraction_chunks_total];
            if (count($seen) === 2) {
                throw new InvalidModelOutputException('Event model returned invalid JSON');
            }
            return $this->chunkResult([]);
        });

        $this->markQueued($stream, 'event_extraction');

        (new ExtractStreamEventsJob($stream->id))->handle($worker, app(WorkerPool::class), app(\App\Services\StreamJobCanceller::class));

        // Three chunks plus the storyline of the whole stream.
        $this->assertSame([[0, 4], [1, 4], [2, 4]], $seen);
        $stream->refresh();
        $this->assertSame(4, $stream->event_extraction_chunks_done);
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

    public function test_the_model_gets_who_speaks_the_streamer_and_the_players_and_the_story_is_stored(): void
    {
        config(['services.event_worker.chunk_seconds' => 10, 'services.event_worker.overlap_seconds' => 0]);
        $stream = $this->createStream('completed');
        Player::create(['name' => 'Jeremy']);
        $stream->speakerNames()->create(['speaker' => 1, 'label' => 'Gast']);
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '0.000', 'end_time' => '2.000', 'text' => 'Hoi chat', 'speaker' => 0]);
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '3.000', 'end_time' => '5.000', 'text' => 'Hallo', 'speaker' => 1]);
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '6.000', 'end_time' => '7.000', 'text' => 'Wie ben jij?', 'speaker' => 2]);
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '12.000', 'end_time' => '14.000', 'text' => 'Jeremy valt me aan!', 'speaker' => 0]);
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '15.000', 'end_time' => '17.000', 'text' => 'Ik ben dood.', 'speaker' => 0]);
        $worker = $this->workerMock(fn ($mock) => $mock->shouldReceive('summarizeStory')->once()->andReturnUsing(function ($claimed, array $parts, array $events, array $context): array {
            // Only parts where something happens in the game, and the events in time order.
            $this->assertSame([['start_time' => 12.0, 'end_time' => 17.0, 'summary' => 'Jeremy vermoordt de streamer.']], $parts);
            $this->assertSame(['Jeremy vermoordt de streamer'], array_column($events, 'title'));
            return ['summary' => 'De streamer wordt door Jeremy vermoord.', 'players' => ['Jeremy']];
        }));
        $worker->shouldReceive('extract')->twice()->andReturnUsing(function ($claimed, array $segments, array $context) use ($stream): array {
            $this->assertSame($stream->player->name, $context['streamer']);
            $this->assertContains('Jeremy', $context['players']);
            if ($segments[0]['text'] === 'Hoi chat') {
                // The streamer by name, a speaker named by hand, and an unknown voice.
                $this->assertSame([$stream->player->name, 'Gast', 'Spreker 2'], array_column($segments, 'speaker'));

                return $this->chunkResult([], '');
            }

            return $this->chunkResult([['type' => 'combat', 'title' => 'Jeremy vermoordt de streamer', 'description' => 'Jeremy valt aan.', 'confidence' => 0.9, 'segment_indexes' => [0, 1]]], 'Jeremy vermoordt de streamer.');
        });

        $this->markQueued($stream, 'event_extraction');
        (new ExtractStreamEventsJob($stream->id))->handle($worker, app(WorkerPool::class), app(\App\Services\StreamJobCanceller::class));

        $stream->refresh();
        $this->assertSame('De streamer wordt door Jeremy vermoord.', $stream->story_summary);
        $this->assertSame(['Jeremy'], $stream->story_players);
        $this->assertSame([['start_time' => 12, 'end_time' => 17, 'summary' => 'Jeremy vermoordt de streamer.']], $stream->story_parts);
        $this->assertNull($stream->event_extraction_error);
    }

    public function test_a_failed_storyline_keeps_the_events_with_a_note(): void
    {
        $stream = $this->createStream('completed');
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '1.000', 'end_time' => '3.000', 'text' => 'Sam valt aan']);
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '4.000', 'end_time' => '6.000', 'text' => 'Sam is dood']);
        $worker = $this->workerMock(fn ($mock) => $mock->shouldReceive('summarizeStory')->once()->andThrow(new InvalidModelOutputException('Het eventmodel gaf geen geldige JSON terug')));
        $worker->shouldReceive('extract')->once()->andReturn($this->chunkResult([['type' => 'combat', 'title' => 'Vecht met Sam', 'description' => 'Een gevecht.', 'confidence' => 0.9, 'segment_indexes' => [0, 1]]], 'Gevecht met Sam.'));

        $this->markQueued($stream, 'event_extraction');
        (new ExtractStreamEventsJob($stream->id))->handle($worker, app(WorkerPool::class), app(\App\Services\StreamJobCanceller::class));

        $stream->refresh();
        $this->assertSame('completed', $stream->event_extraction_status);
        $this->assertSame(['Vecht met Sam'], Event::pluck('title')->all());
        $this->assertNull($stream->story_summary);
        $this->assertStringContainsString('verhaal van de stream kon niet worden geschreven', $stream->event_extraction_error);
    }

    /** A mock worker; writing the storyline returns an empty story unless $configure sets it up. */
    private function workerMock(?\Closure $configure = null): \Mockery\MockInterface
    {
        $mock = Mockery::mock(EventExtractionWorker::class);
        if ($configure !== null) {
            $configure($mock);
        } else {
            $mock->shouldReceive('summarizeStory')->andReturn(['summary' => '', 'players' => []]);
        }

        return $mock;
    }

    /** What the worker answers for one chunk. */
    private function chunkResult(array $events, string $summary = 'Er gebeurt iets in de game.'): array
    {
        return ['events' => $events, 'summary' => $summary];
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
