<?php

namespace App\Services;

use App\Models\SpeakerTextMatch;
use App\Models\Stream;
use App\Models\StreamSpeaker;
use App\Models\TranscriptSegment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Recognises a speaker by what they say: when players talk together, the other player's words are in both streams at
 * the same server time (stream start + video offset + file time). A speaker of stream A whose sentences are also said
 * by the streamer of stream B at that moment is B's player. This works where voices differ (a Discord or in-game voice
 * sounds different from the player's own microphone) and needs no voice profile.
 *
 * Comparing transcripts is too slow for a page view, so refresh() stores the counts per speaker and other stream
 * (speaker_text_matches) after a transcription; matches() picks the players from those counts.
 */
class SpeakerTextMatches
{
    /** Same moment: the clocks of two streams can be a few seconds apart (stream delay, rounding of the start). */
    public const TOLERANCE_SECONDS = 15;

    /** A sentence counts when this share of its word pairs is said in the other stream at that time. */
    public const MIN_CONTAINMENT = 0.6;

    /** Shorter sentences ("ja, dat klopt") are said by everyone. */
    public const MIN_WORD_PAIRS = 4;

    /** Sentences needed before a speaker is shown as that player. */
    public const MIN_HITS = 3;

    /** And the best player needs at least this many times the hits of the next one. */
    public const MIN_LEAD = 2.0;

    /**
     * Recomputes the matches between this stream and every other player's stream that was live at the same time, in
     * both directions: this stream's speakers against their streamer, and their speakers against this streamer.
     *
     * @return int the number of stored matches
     */
    public function refresh(Stream $stream): int
    {
        $rows = [];
        if ($stream->started_at !== null) {
            foreach ($this->overlappingStreams($stream) as $other) {
                array_push($rows, ...$this->compare($stream, $other), ...$this->compare($other, $stream));
            }
        }

        DB::transaction(function () use ($stream, $rows): void {
            SpeakerTextMatch::query()->where('stream_id', $stream->id)->orWhere('other_stream_id', $stream->id)->delete();
            $now = now();
            foreach (array_chunk($rows, 500) as $chunk) {
                SpeakerTextMatch::query()->insert(array_map(fn (array $row) => [...$row, 'created_at' => $now, 'updated_at' => $now], $chunk));
            }
        });
        VoiceProfiles::forget();

        return count($rows);
    }

    /**
     * The player each speaker of the stream is, by text: enough hits, clearly ahead of the next player, each player
     * once, and never the stream's own player or a player already named in it. Speakers named by hand are left out.
     *
     * @param  Collection<int, SpeakerTextMatch>|null  $rows  the stream's matches (loaded when null)
     * @param  Collection<int, StreamSpeaker>|null  $names  the stream's names by hand (loaded when null)
     * @return array<int, array{player_id: int, hits: int, compared: int, stream_id: int}> per speaker number
     */
    public function matches(Stream $stream, ?Collection $rows = null, ?Collection $names = null): array
    {
        $rows ??= $stream->speakerTextMatches()->get();
        $names = ($names ?? $stream->speakerNames()->get())->keyBy('speaker');
        $taken = [$stream->player_id, ...$names->pluck('player_id')->filter()->all()];

        $candidates = [];
        foreach ($rows->groupBy('speaker') as $speaker => $speakerRows) {
            if ($names->has($speaker)) {
                continue;
            }
            $perPlayer = $speakerRows->groupBy('player_id')->map(fn (Collection $items) => [
                'hits' => $items->sum('hits'),
                'compared' => $items->sum('compared'),
                'stream_id' => $items->sortByDesc('hits')->first()->other_stream_id,
            ])->sortByDesc('hits');
            $best = $perPlayer->first();
            $playerId = $perPlayer->keys()->first();
            $next = $perPlayer->skip(1)->first()['hits'] ?? 0;
            if ($best['hits'] >= self::MIN_HITS && $best['hits'] >= self::MIN_LEAD * $next) {
                $candidates[] = ['speaker' => (int) $speaker, 'player_id' => (int) $playerId, ...$best];
            }
        }

        $matches = [];
        foreach (collect($candidates)->sortByDesc('hits') as $candidate) {
            if (in_array($candidate['player_id'], $taken, true)) {
                continue;
            }
            $matches[$candidate['speaker']] = ['player_id' => $candidate['player_id'], 'hits' => $candidate['hits'], 'compared' => $candidate['compared'], 'stream_id' => $candidate['stream_id']];
            $taken[] = $candidate['player_id'];
        }

        return $matches;
    }

    /**
     * $from's speakers (not its own streamer) against what $to's streamer says at the same time.
     *
     * @return list<array{stream_id: int, speaker: int, other_stream_id: int, player_id: int, hits: int, compared: int}>
     */
    public function compare(Stream $from, Stream $to): array
    {
        $fromBase = $this->base($from);
        $toBase = $this->base($to);
        $start = max($from->started_at->getTimestamp(), $to->started_at->getTimestamp()) - self::TOLERANCE_SECONDS;
        $end = min($this->endOf($from), $this->endOf($to)) + self::TOLERANCE_SECONDS;
        if ($end <= $start) {
            return [];
        }

        $ownFrom = $this->streamerSpeakers($from);
        $ownTo = $this->streamerSpeakers($to);
        if ($ownTo === []) {
            return [];
        }
        $theirs = $this->segments($to, $start - $toBase, $end - $toBase)
            ->filter(fn (TranscriptSegment $segment) => in_array($segment->speaker, $ownTo, true))
            ->map(fn (TranscriptSegment $segment) => [
                'start' => $toBase + (float) $segment->start_time,
                'end' => $toBase + (float) $segment->end_time,
                'pairs' => self::wordPairs($segment->text),
            ])
            ->values()
            ->all();
        if ($theirs === []) {
            return [];
        }

        $counts = [];
        $first = 0;
        foreach ($this->segments($from, $start - $fromBase, $end - $fromBase) as $segment) {
            if ($segment->speaker === null || in_array($segment->speaker, $ownFrom, true)) {
                continue;
            }
            $pairs = self::wordPairs($segment->text);
            if (count($pairs) < self::MIN_WORD_PAIRS) {
                continue;
            }
            $windowStart = $fromBase + (float) $segment->start_time - self::TOLERANCE_SECONDS;
            $windowEnd = $fromBase + (float) $segment->end_time + self::TOLERANCE_SECONDS;
            // Both lists are in time order, so the window only moves forward. A segment rarely lasts long, so skipping
            // the ones that ended before the window (by start time + a minute) is safe.
            while ($first < count($theirs) && $theirs[$first]['start'] < $windowStart - 60) {
                $first++;
            }
            $said = [];
            for ($i = $first; $i < count($theirs) && $theirs[$i]['start'] <= $windowEnd; $i++) {
                if ($theirs[$i]['end'] >= $windowStart) {
                    $said += $theirs[$i]['pairs'];
                }
            }
            if ($said === []) {
                continue;
            }

            $speaker = $segment->speaker;
            $counts[$speaker] ??= ['hits' => 0, 'compared' => 0];
            $counts[$speaker]['compared']++;
            if (count(array_intersect_key($pairs, $said)) / count($pairs) >= self::MIN_CONTAINMENT) {
                $counts[$speaker]['hits']++;
            }
        }

        $rows = [];
        foreach ($counts as $speaker => $count) {
            if ($count['hits'] > 0) {
                $rows[] = ['stream_id' => $from->id, 'speaker' => $speaker, 'other_stream_id' => $to->id, 'player_id' => $to->player_id, ...$count];
            }
        }

        return $rows;
    }

    /**
     * The distinct word pairs of a sentence, lowercase without punctuation, as array keys. Pairs instead of single
     * words, because common words are in every window; the same run of words rarely is by accident.
     *
     * @return array<string, true>
     */
    public static function wordPairs(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $pairs = [];
        for ($i = 1; $i < count($words); $i++) {
            $pairs[$words[$i - 1].' '.$words[$i]] = true;
        }

        return $pairs;
    }

    /**
     * The speakers that are the stream's own player: named after them by hand, or speaker 0 while nobody named it.
     *
     * @return list<int>
     */
    private function streamerSpeakers(Stream $stream): array
    {
        $names = $stream->speakerNames()->get()->keyBy('speaker');
        $speakers = $names->filter(fn (StreamSpeaker $name) => $name->player_id === $stream->player_id)->keys()->all();
        if (! $names->has(0) && $stream->transcriptSegments()->where('speaker', 0)->exists()) {
            $speakers[] = 0;
        }

        return array_map('intval', $speakers);
    }

    /** @return Collection<int, TranscriptSegment> the stream's segments between two file times, in time order */
    private function segments(Stream $stream, float $from, float $until): Collection
    {
        return $stream->transcriptSegments()
            ->whereNotNull('speaker')
            ->where('end_time', '>=', $from)
            ->where('start_time', '<=', $until)
            ->orderBy('start_time')
            ->get(['id', 'start_time', 'end_time', 'text', 'speaker']);
    }

    /** @return Collection<int, Stream> other players' transcribed streams that were live at the same time */
    private function overlappingStreams(Stream $stream): Collection
    {
        return Stream::query()
            ->where('player_id', '!=', $stream->player_id)
            ->where('transcription_status', 'completed')
            ->whereNotNull('transcription_speakers')
            ->where('started_at', '<=', date(DATE_ATOM, $this->endOf($stream)))
            ->where(fn ($query) => $query->whereNull('ended_at')->orWhere('ended_at', '>=', $stream->started_at))
            ->get();
    }

    /** Server time (Unix seconds) of time 0 in the stream's media file. */
    private function base(Stream $stream): float
    {
        return $stream->started_at->getTimestamp() + (float) ($stream->video_offset_seconds ?? 0);
    }

    /** When the stream ended, or what its transcript covers when no end is known. */
    private function endOf(Stream $stream): int
    {
        return $stream->ended_at?->getTimestamp()
            ?? (int) ceil($this->base($stream) + (float) ($stream->transcription_duration_seconds ?? $stream->transcriptSegments()->max('end_time') ?? 0));
    }
}
