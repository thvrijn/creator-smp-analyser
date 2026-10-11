<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Player;
use App\Models\Stream;
use App\Models\TranscriptSegment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StreamAnalysisPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_stream_page_gives_the_parts_and_the_players_recent_events(): void
    {
        $player = Player::create(['name' => 'Sophie']);
        $older = Stream::create(['player_id' => $player->id, 'title' => 'Gisteren', 'started_at' => '2026-10-02 18:00:00+00', 'source' => 'test']);
        Event::create(['stream_id' => $older->id, 'type' => 'conflict', 'title' => 'Ruzie met Lars', 'description' => 'Ruzie.', 'start_time' => 3600, 'end_time' => 3700, 'confidence' => 0.9]);
        $stream = Stream::create(['player_id' => $player->id, 'title' => 'Vandaag', 'started_at' => '2026-10-03 18:00:00+00', 'source' => 'test']);
        $own = Event::create(['stream_id' => $stream->id, 'type' => 'plot', 'title' => 'Eigen event', 'description' => 'Niet in de context.', 'start_time' => 5, 'end_time' => 134, 'confidence' => 0.8]);
        $segments = [];
        foreach ([[5, 0], [70, 0], [130, 1], [650, 1], [660, null]] as [$start, $speaker]) {
            $segments[] = TranscriptSegment::create(['stream_id' => $stream->id, 'start_time' => $start, 'end_time' => $start + 4, 'text' => 'Zin '.$start, 'speaker' => $speaker]);
        }
        $own->transcriptSegments()->attach([$segments[0]->id, $segments[1]->id, $segments[2]->id]);

        $this->get('/streams/'.$stream->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Streams/Show')
            // Per minute: how many lines and who says them.
            ->where('speech_minutes.0', ['lines' => 1, 'speakers' => ['0' => 1]])
            ->where('speech_minutes.2', ['lines' => 1, 'speakers' => ['1' => 1]])
            ->where('speech_minutes.11', ['lines' => 1, 'speakers' => []])
            ->where('speech_minutes.10', ['lines' => 1, 'speakers' => ['1' => 1]])
            // Who speaks in an event.
            ->where('events.0.speakers', ['0' => 2, '1' => 1])
            // The parts the analysis sends to the model (5 minutes from the first line, every 4 minutes), from their
            // first to their last line; parts without lines are left out.
            ->where('analysis_parts', [['start_time' => 5, 'end_time' => 134], ['start_time' => 650, 'end_time' => 664]])
            // Only events from the player's other streams, with when they happened.
            ->has('player_events', 1)
            ->where('player_events.0.title', 'Ruzie met Lars')
            ->where('player_events.0.stream_id', $older->id)
            ->where('player_events.0.at', '2026-10-02T19:00:00+00:00')
            ->has('players.0.photo_url'));
    }

    public function test_the_sidebar_gets_the_active_jobs_running_first(): void
    {
        $player = Player::create(['name' => 'Sophie']);
        Stream::create(['player_id' => $player->id, 'title' => 'Klaar', 'started_at' => now(), 'source' => 'test', 'transcription_status' => 'completed']);
        Stream::create(['player_id' => $player->id, 'title' => 'Wacht', 'started_at' => now(), 'source' => 'test', 'transcription_status' => 'queued']);
        Stream::create(['player_id' => $player->id, 'title' => 'Draait', 'started_at' => now(), 'source' => 'test', 'event_extraction_status' => 'processing']);

        $this->get('/players')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('active_jobs', 2)
            ->where('active_jobs.0.title', 'Draait')
            ->where('active_jobs.1.title', 'Wacht'));
    }

    public function test_search_finds_players_streams_and_events(): void
    {
        $sophie = Player::create(['name' => 'Sophie']);
        Player::create(['name' => 'Lars']);
        $stream = Stream::create(['player_id' => $sophie->id, 'title' => 'Sophie bouwt een kasteel', 'started_at' => now(), 'source' => 'test']);
        $event = Event::create(['stream_id' => $stream->id, 'type' => 'building', 'title' => 'Kasteelmuur af', 'description' => 'Sophie maakt de muur af.', 'start_time' => 60, 'end_time' => 90, 'confidence' => 0.8]);

        $this->getJson('/search?q=sophie')->assertOk()
            ->assertJsonPath('players.0.name', 'Sophie')
            ->assertJsonCount(1, 'players')
            ->assertJsonPath('streams.0.id', $stream->id)
            ->assertJsonPath('streams.0.player', 'Sophie')
            // Found by its description.
            ->assertJsonPath('events.0.id', $event->id)
            ->assertJsonPath('events.0.stream_id', $stream->id);

        $this->getJson('/search?q=KASTEEL')->assertOk()->assertJsonCount(0, 'players')->assertJsonCount(1, 'streams')->assertJsonCount(1, 'events');
        // Too short, and LIKE wildcards are taken literally.
        $this->getJson('/search?q=s')->assertOk()->assertJsonCount(0, 'players');
        $this->getJson('/search?q=%25%25')->assertOk()->assertJsonCount(0, 'players')->assertJsonCount(0, 'streams');
    }

    public function test_the_example_pages_render(): void
    {
        $this->get('/storylines')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Storylines'));
        $this->get('/episodes')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Episodes'));
    }
}
