<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\Stream;
use App\Models\TranscriptSegment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TranscriptSegmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_transcript_segment_can_be_created_and_saved_with_precise_values(): void
    {
        $stream = $this->createStream();

        $segment = TranscriptSegment::create([
            'stream_id' => $stream->id,
            'start_time' => '872.420',
            'end_time' => '876.810',
            'text' => 'Waar is Lars?',
        ]);

        $this->assertDatabaseHas('transcript_segments', [
            'id' => $segment->id,
            'stream_id' => $stream->id,
            'start_time' => '872.420',
            'end_time' => '876.810',
            'text' => 'Waar is Lars?',
        ]);
        $this->assertSame('872.420', $segment->start_time);
        $this->assertSame('876.810', $segment->end_time);
    }

    public function test_a_transcript_segment_can_be_linked_to_a_stream_and_relationships_work(): void
    {
        $stream = $this->createStream();
        $segment = TranscriptSegment::create([
            'stream_id' => $stream->id,
            'start_time' => '1.000',
            'end_time' => '2.500',
            'text' => 'Testsegment',
        ]);

        $this->assertTrue($stream->transcriptSegments->contains($segment));
        $this->assertTrue($segment->stream->is($stream));
    }

    public function test_a_transcript_segment_requires_a_valid_stream(): void
    {
        $this->expectException(QueryException::class);

        TranscriptSegment::create([
            'stream_id' => 999999,
            'start_time' => '1.000',
            'end_time' => '2.000',
            'text' => 'Ongeldig segment',
        ]);
    }

    public function test_transcript_segments_are_deleted_when_their_stream_is_deleted(): void
    {
        $stream = $this->createStream();
        $segment = TranscriptSegment::create([
            'stream_id' => $stream->id,
            'start_time' => '1.000',
            'end_time' => '2.000',
            'text' => 'Wordt verwijderd',
        ]);

        $stream->delete();

        $this->assertDatabaseMissing('transcript_segments', ['id' => $segment->id]);
    }

    private function createStream(): Stream
    {
        $player = Player::create(['name' => 'Sophie']);

        return Stream::create([
            'player_id' => $player->id,
            'title' => 'Stream #1',
            'started_at' => '2026-10-03 10:00:00+00',
            'source' => 'development',
        ]);
    }
}
