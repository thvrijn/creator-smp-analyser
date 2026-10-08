<?php

namespace App\Jobs;

use App\Models\Stream;
use App\Services\SpeakerTextMatches;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Compares a stream's transcript with the other players' streams that were live at the same time, to recognise
 * speakers by what they say (SpeakerTextMatches). Runs after a transcription and after speaker corrections.
 */
class MatchSpeakerTextJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 1800;

    public int $tries = 2;

    public function __construct(public readonly int $streamId) {}

    public function uniqueId(): string
    {
        return (string) $this->streamId;
    }

    public function handle(SpeakerTextMatches $matches): void
    {
        $stream = Stream::find($this->streamId);
        if ($stream === null || $stream->transcription_status !== 'completed') {
            return;
        }

        $matches->refresh($stream);
    }
}
