<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Player;
use App\Models\Stream;
use App\Models\TranscriptSegment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TranscriptViewerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_stream_audio_can_be_played_and_seeked(): void
    {
        Storage::fake('local');
        $player = Player::create(['name' => 'Sophie']);
        $stream = Stream::create(['player_id' => $player->id, 'title' => 'Audio', 'started_at' => '2026-10-03 10:00:00+00', 'source' => 'test', 'video_path' => 'streams/1/audio.m4a', 'video_mime_type' => 'audio/mp4']);
        Storage::disk('local')->put($stream->video_path, '0123456789');

        $response = $this->get('/streams/'.$stream->id.'/audio');
        $response->assertOk()->assertHeader('Content-Type', 'audio/mp4')->assertHeader('Accept-Ranges', 'bytes');
        $this->assertSame('0123456789', $response->streamedContent());

        // The player seeks with Range requests.
        $partial = $this->get('/streams/'.$stream->id.'/audio', ['Range' => 'bytes=4-6']);
        $partial->assertStatus(206)->assertHeader('Content-Range', 'bytes 4-6/10');
        $this->assertSame('456', $partial->streamedContent());

        $stream->update(['video_path' => null]);
        $this->get('/streams/'.$stream->id.'/audio')->assertNotFound();
    }

    public function test_transcript_page_returns_segments_in_chronological_order(): void
    {
        $stream = $this->createStream();
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '12.000', 'end_time' => '14.000', 'text' => 'Second']);
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '2.000', 'end_time' => '5.000', 'text' => 'First']);

        $this->get('/streams/'.$stream->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Streams/Show')
                ->where('stream.title', 'Evening SMP')
                ->where('stream.segment_count', 2)
                ->where('segments.total', 2)
                ->where('segments.data.0.text', 'First')
                ->where('segments.data.1.text', 'Second'));
    }

    public function test_transcript_page_is_paginated(): void
    {
        $stream = $this->createStream();
        for ($index = 0; $index < 120; $index++) {
            TranscriptSegment::create([
                'stream_id' => $stream->id,
                'start_time' => number_format($index * 10, 3, '.', ''),
                'end_time' => number_format(($index * 10) + 4, 3, '.', ''),
                'text' => 'Segment '.$index,
            ]);
        }

        $this->get('/streams/'.$stream->id.'?page=2')
            ->assertInertia(fn (Assert $page) => $page
                ->where('segments.current_page', 2)
                ->where('segments.data.0.text', 'Segment 50')
                ->where('segments.data.49.text', 'Segment 99')
                ->where('segments.total', 120));
    }

    public function test_transcript_search_is_case_insensitive_and_preserves_pagination(): void
    {
        $stream = $this->createStream();
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '1.000', 'end_time' => '2.000', 'text' => 'Ik zoek naar diamant']);
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '3.000', 'end_time' => '4.000', 'text' => 'Er zit een creeper']);
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '5.000', 'end_time' => '6.000', 'text' => 'DIAMANT gevonden']);

        $this->get('/streams/'.$stream->id.'?search=DiAmAnT&page=1')
            ->assertInertia(fn (Assert $page) => $page
                ->where('search', 'DiAmAnT')
                ->where('segments.total', 2)
                ->has('segments.data', 2)
                ->where('segments.data.1.text', 'DIAMANT gevonden')
                ->where('pagination', fn ($links) => $links->contains(fn ($link) => str_contains((string) ($link['url'] ?? ''), 'search=DiAmAnT'))));
    }

    public function test_empty_search_returns_all_segments_and_missing_search_has_empty_state_data(): void
    {
        $stream = $this->createStream();
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '1.000', 'end_time' => '2.000', 'text' => 'Hello']);

        $this->get('/streams/'.$stream->id.'?search=does-not-exist')
            ->assertInertia(fn (Assert $page) => $page
                ->where('search', 'does-not-exist')
                ->where('segments.total', 0)
                ->has('segments.data', 0));
    }

    public function test_transcript_segments_are_isolated_to_the_requested_stream(): void
    {
        $stream = $this->createStream();
        $otherStream = $this->createStream('Other stream');
        TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '1.000', 'end_time' => '2.000', 'text' => 'Correct stream']);
        TranscriptSegment::create(['stream_id' => $otherStream->id, 'start_time' => '1.000', 'end_time' => '2.000', 'text' => 'Other stream']);

        $this->get('/streams/'.$stream->id)
            ->assertInertia(fn (Assert $page) => $page
                ->where('segments.total', 1)
                ->where('segments.data.0.text', 'Correct stream'));
    }

    public function test_stream_page_lists_the_streams_events_in_time_order(): void
    {
        $stream = $this->createStream();
        $other = $this->createStream('Other stream');
        $segment = TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => '1.000', 'end_time' => '2.000', 'text' => 'Creeper!']);
        $this->createEvent($stream, 'Later', 50, [$segment->id]);
        $this->createEvent($stream, 'Earlier', 1, [$segment->id]);
        $this->createEvent($other, 'Not mine', 1, []);

        $this->get('/streams/'.$stream->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Streams/Show')
                ->where('stream.title', 'Evening SMP')
                ->where('stream.transcription_status', 'pending')
                ->has('events', 2)
                ->where('events.0.title', 'Earlier')
                ->where('events.0.segment_count', 1)
                ->where('events.1.title', 'Later')
                ->where('selected_event_id', null)
                ->where('highlighted_segment_ids', []));
    }

    public function test_selecting_an_event_opens_the_transcript_page_with_its_segments_highlighted(): void
    {
        $stream = $this->createStream();
        $segments = collect(range(1, 120))->map(fn (int $second) => TranscriptSegment::create([
            'stream_id' => $stream->id,
            'start_time' => number_format($second, 3, '.', ''),
            'end_time' => number_format($second + 0.5, 3, '.', ''),
            'text' => 'Line '.$second,
        ]));
        $event = $this->createEvent($stream, 'Late event', 75, [$segments[74]->id, $segments[75]->id]);

        $this->get('/streams/'.$stream->id.'?event='.$event->id)
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected_event_id', $event->id)
                ->where('highlighted_segment_ids', [$segments[74]->id, $segments[75]->id])
                ->where('segments.current_page', 2)
                ->where('segments.data.24.text', 'Line 75'));

        $this->get('/streams/'.$stream->id.'?event='.$event->id.'&page=1')
            ->assertInertia(fn (Assert $page) => $page->where('segments.current_page', 1)->where('selected_event_id', $event->id));
    }

    public function test_event_of_another_stream_cannot_be_selected(): void
    {
        $stream = $this->createStream();
        $other = $this->createStream('Other stream');
        $event = $this->createEvent($other, 'Not mine', 1, []);

        $this->get('/streams/'.$stream->id.'?event='.$event->id)->assertNotFound();
    }

    public function test_old_transcript_url_redirects_to_the_stream_page(): void
    {
        $stream = $this->createStream();

        $this->get('/streams/'.$stream->id.'/transcript?search=creeper&page=2')
            ->assertRedirect('/streams/'.$stream->id.'?page=2&search=creeper')
            ->assertStatus(301);
    }

    private function createEvent(Stream $stream, string $title, float $start, array $segmentIds): Event
    {
        $event = Event::create(['stream_id' => $stream->id, 'type' => 'death', 'title' => $title, 'description' => 'Something happened.', 'start_time' => $start, 'end_time' => $start + 1, 'confidence' => 0.8]);
        $event->transcriptSegments()->sync($segmentIds);

        return $event;
    }

    private function createStream(string $title = 'Evening SMP'): Stream
    {
        $player = Player::create(['name' => 'Sophie '.uniqid()]);

        return Stream::create([
            'player_id' => $player->id,
            'title' => $title,
            'started_at' => '2026-10-03 19:00:00+00',
            'ended_at' => '2026-10-03 20:00:00+00',
            'source' => 'development',
            'transcription_duration_seconds' => 3600,
        ]);
    }
}
