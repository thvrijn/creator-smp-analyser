<?php

namespace App\Console\Commands;

use App\Models\Stream;
use App\Services\SpeakerTextMatches;
use Illuminate\Console\Command;

/** Recognises speakers by what they say, for streams transcribed before this existed or to redo it after a change. */
class MatchSpeakerText extends Command
{
    protected $signature = 'speakers:match-text {stream?* : Stream ids (default: every transcribed stream with speakers)}';

    protected $description = 'Compare transcripts of streams that were live at the same time to recognise speakers by what they say';

    public function handle(SpeakerTextMatches $matches): int
    {
        $ids = $this->argument('stream');
        $streams = Stream::query()
            ->where('transcription_status', 'completed')
            ->whereNotNull('transcription_speakers')
            ->when($ids !== [], fn ($query) => $query->whereKey($ids))
            ->orderBy('started_at')
            ->get();

        foreach ($streams as $stream) {
            $started = microtime(true);
            $count = $matches->refresh($stream);
            $recognised = collect($matches->matches($stream))->map(fn (array $match, int $speaker) => "spreker {$speaker} → speler #{$match['player_id']} ({$match['hits']}/{$match['compared']})")->implode(', ');
            $this->line(sprintf('#%d %s: %d matches in %.1fs%s', $stream->id, $stream->title, $count, microtime(true) - $started, $recognised !== '' ? ' · '.$recognised : ''));
        }
        $this->info("Done: {$streams->count()} streams.");

        return self::SUCCESS;
    }
}
