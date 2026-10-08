<?php

namespace App\Services;

use App\Models\Player;
use App\Models\Stream;
use App\Models\TranscriptSegment;
use Illuminate\Support\Facades\DB;

/**
 * Corrections to a stream's diarized speakers: naming a speaker (a player, a free name, or explicitly unknown), merging
 * two speakers that are the same person, and moving a single segment to another speaker. Without a name, speaker 0 is
 * shown as the stream's player (it speaks the most), another speaker as the player its voice matches (VoiceProfiles),
 * and otherwise as "Spreker n".
 */
class StreamSpeakers
{
    public function __construct(private readonly VoiceProfiles $voices, private readonly SpeakerTextMatches $texts) {}

    /**
     * Every speaker in the stream, with its name, speaking time and segment count, ordered by number.
     *
     * source: named (a player or free name given by hand), unknown (set to unknown by hand), matched (recognised: by
     * text, saying the same sentences as that player in their stream, or else by voice, with its similarity), or
     * default (speaker 0 = the streamer, others "Spreker n").
     *
     * @return list<array{speaker: int, name: string, source: string, named: bool, player_id: ?int, label: ?string, matched_by: ?string, similarity: ?float, text_hits: ?int, text_stream_id: ?int, seconds: float, segment_count: int}>
     */
    public function list(Stream $stream): array
    {
        $stats = $stream->transcriptSegments()
            ->whereNotNull('speaker')
            ->groupBy('speaker')
            ->selectRaw('speaker, count(*) as segment_count, sum(end_time - start_time) as seconds')
            ->get()
            ->keyBy('speaker');
        $names = $stream->speakerNames()->with('player:id,name')->get()->keyBy('speaker');
        $matches = $this->recognised($stream);
        $matchedPlayers = Player::query()->whereKey(array_column($matches, 'player_id'))->pluck('name', 'id');
        $numbers = $stats->keys()->merge($names->keys())->unique()->sort()->values();

        return $numbers->map(function (int $speaker) use ($stream, $stats, $names, $matches, $matchedPlayers): array {
            $name = $names->get($speaker);
            $match = $name === null ? ($matches[$speaker] ?? null) : null;
            [$source, $label] = match (true) {
                $name?->player !== null => ['named', $name->player->name],
                filled($name?->label) => ['named', $name->label],
                $name !== null => ['unknown', "Spreker {$speaker}"],
                $match !== null && $matchedPlayers->has($match['player_id']) => ['matched', $matchedPlayers[$match['player_id']]],
                default => ['default', $this->defaultName($stream, $speaker)],
            };

            return [
                'speaker' => $speaker,
                'name' => $label,
                'source' => $source,
                'named' => $name !== null,
                'player_id' => $name?->player_id ?? ($source === 'matched' ? $match['player_id'] : null),
                'label' => $name?->label,
                'matched_by' => $source === 'matched' ? $match['by'] : null,
                'similarity' => $source === 'matched' ? ($match['similarity'] ?? null) : null,
                'text_hits' => $source === 'matched' ? ($match['hits'] ?? null) : null,
                'text_stream_id' => $source === 'matched' ? ($match['stream_id'] ?? null) : null,
                'seconds' => round((float) ($stats->get($speaker)?->seconds ?? 0), 1),
                'segment_count' => (int) ($stats->get($speaker)?->segment_count ?? 0),
            ];
        })->all();
    }

    /**
     * The player behind each speaker that is known as one: named after a player by hand, recognised by voice, or
     * speaker 0 as the streamer while nobody named it otherwise.
     *
     * @return array<int, int> player id per speaker number
     */
    public function players(Stream $stream): array
    {
        $names = $stream->speakerNames()->get()->keyBy('speaker');
        $players = array_map(fn (array $match) => $match['player_id'], $this->recognised($stream));
        if (! $names->has(0)) {
            $players[0] = $stream->player_id;
        }
        foreach ($names as $speaker => $name) {
            if ($name->player_id !== null) {
                $players[$speaker] = $name->player_id;
            }
        }

        return $players;
    }

    /**
     * Unnamed speakers recognised as a player: by text first (they say the same sentences as that player in their own
     * stream, the stronger evidence), then by voice for the rest.
     *
     * @return array<int, array{player_id: int, by: string, similarity?: float, hits?: int, compared?: int, stream_id?: int}> per speaker number
     */
    public function recognised(Stream $stream): array
    {
        $byText = array_map(fn (array $match) => [...$match, 'by' => 'text'], $this->texts->matches($stream));
        $byVoice = array_map(fn (array $match) => [...$match, 'by' => 'voice'], $this->voices->matches($stream, array_map(fn (array $match) => $match['player_id'], $byText)));

        return $byText + $byVoice;
    }

    public function defaultName(Stream $stream, int $speaker): string
    {
        return $speaker === 0 ? $stream->player->name : "Spreker {$speaker}";
    }

    /**
     * Names a speaker after a player or with a free label. $unknown says "not anyone we know" (and stops voice matching
     * for it); none of them clears the name, back to the default or the voice match.
     */
    public function name(Stream $stream, int $speaker, ?int $playerId, ?string $label, bool $unknown = false): void
    {
        if ($unknown) {
            $stream->speakerNames()->updateOrCreate(['speaker' => $speaker], ['player_id' => null, 'label' => null]);

            return;
        }
        if ($playerId === null && blank($label)) {
            $stream->speakerNames()->where('speaker', $speaker)->delete();
            VoiceProfiles::forget();

            return;
        }

        $stream->speakerNames()->updateOrCreate(['speaker' => $speaker], [
            'player_id' => $playerId,
            'label' => $playerId === null ? trim((string) $label) : null,
        ]);
    }

    /** Makes $from part of $into: its segments, speaking time and voice, and its name when $into has none. */
    public function merge(Stream $stream, int $from, int $into): void
    {
        DB::transaction(function () use ($stream, $from, $into): void {
            $stream->transcriptSegments()->where('speaker', $from)->update(['speaker' => $into]);

            $fromName = $stream->speakerNames()->where('speaker', $from)->first();
            if ($fromName !== null && $stream->speakerNames()->where('speaker', $into)->doesntExist()) {
                $fromName->update(['speaker' => $into]);
            } else {
                $fromName?->delete();
            }

            $stream->forceFill(['transcription_speakers' => $this->mergedVoices($stream->transcription_speakers, $from, $into)])->save();
        });
    }

    /**
     * Moves one segment to another speaker (null: nobody). 'new' gives it a speaker number of its own, to name afterwards.
     *
     * @return int|null the segment's speaker now
     */
    public function moveSegment(TranscriptSegment $segment, int|string|null $speaker): ?int
    {
        if ($speaker === 'new') {
            $stream = $segment->stream;
            $speaker = 1 + max(
                (int) $stream->transcriptSegments()->max('speaker'),
                (int) $stream->speakerNames()->max('speaker'),
                (int) collect($stream->transcription_speakers ?? [])->max('speaker'),
            );
        }
        $segment->update(['speaker' => $speaker]);

        return $segment->speaker;
    }

    /** The speaker numbers that exist in the stream, for validation. @return list<int> */
    public function numbers(Stream $stream): array
    {
        return array_column($this->list($stream), 'speaker');
    }

    /**
     * The worker's per-speaker summary with $from folded into $into: speaking time added up, and the voice embedding
     * averaged by speaking time, so it stays the centre of everything that speaker said.
     *
     * @param  list<array{speaker: int, seconds: float, embedding: list<float>|null}>|null  $voices
     * @return list<array{speaker: int, seconds: float, embedding: list<float>|null}>|null
     */
    private function mergedVoices(?array $voices, int $from, int $into): ?array
    {
        if ($voices === null) {
            return null;
        }
        $source = collect($voices)->firstWhere('speaker', $from);
        if ($source === null) {
            return $voices;
        }
        $rest = collect($voices)->reject(fn (array $voice) => $voice['speaker'] === $from);
        $target = $rest->firstWhere('speaker', $into);
        if ($target === null) {
            return $rest->push([...$source, 'speaker' => $into])->sortBy('speaker')->values()->all();
        }

        $seconds = $target['seconds'] + $source['seconds'];
        $embedding = match (true) {
            $target['embedding'] === null => $source['embedding'],
            $source['embedding'] === null || $seconds <= 0 => $target['embedding'],
            default => array_map(
                fn (float $a, float $b) => ($a * $target['seconds'] + $b * $source['seconds']) / $seconds,
                $target['embedding'],
                $source['embedding'],
            ),
        };

        return $rest->map(fn (array $voice) => $voice['speaker'] === $into ? [...$voice, 'seconds' => $seconds, 'embedding' => $embedding] : $voice)->values()->all();
    }
}
