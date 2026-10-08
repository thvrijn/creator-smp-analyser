<?php

namespace App\Services;

use App\Models\Player;
use App\Models\SpeakerTextMatch;
use App\Models\Stream;
use App\Models\StreamSpeaker;
use Illuminate\Support\Facades\Cache;

/**
 * Recognises speakers across streams (diarization step 2). Every player gets a voice profile: the average of the
 * voice embeddings that are known to be theirs. Those are the speakers named after them by hand (confirmed), and
 * speaker 0 of their own streams while nobody named it otherwise (it speaks the most, almost always the streamer), and
 * speakers recognised by saying the same sentences as that player in their own stream (SpeakerTextMatches).
 * A speaker without a name in another stream is shown as the player whose profile its voice is closest to, when that
 * is close enough (services.voices) and clearly closer than the next player. Naming it by hand confirms or corrects it.
 *
 * The profiles are cached and rebuilt after anything they depend on changes (forget()).
 */
class VoiceProfiles
{
    public function __construct(private readonly SpeakerTextMatches $texts) {}

    private const CACHE_KEY = 'voice-profiles:v1';

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<int, array{embedding: list<float>, streams: int}> per player id: normalised mean voice, and from how many streams */
    public function profiles(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            $sums = [];
            foreach ($this->knownVoices() as [$playerId, $embedding]) {
                $sums[$playerId]['sum'] = isset($sums[$playerId]) ? array_map(fn ($a, $b) => $a + $b, $sums[$playerId]['sum'], $embedding) : $embedding;
                $sums[$playerId]['streams'] = ($sums[$playerId]['streams'] ?? 0) + 1;
            }

            return array_map(fn (array $item) => ['embedding' => self::normalise($item['sum']), 'streams' => $item['streams']], $sums);
        });
    }

    /**
     * The best-matching player for each unnamed speaker of the stream (not speaker 0, which defaults to the streamer).
     * Each player is used once per stream, so two voices never get the same name.
     *
     * @param  array<int, int>  $decided  speakers already recognised otherwise (by text): player id per speaker number
     * @return array<int, array{player_id: int, similarity: float}> per speaker number
     */
    public function matches(Stream $stream, array $decided = []): array
    {
        $profiles = $this->profiles();
        $names = $stream->speakerNames()->get()->keyBy('speaker');
        // Players already in this stream: named by hand, recognised by text, or the streamer as the default speaker 0.
        $taken = [...$names->pluck('player_id')->filter()->all(), ...array_values($decided)];
        if (! $names->has(0)) {
            $taken[] = $stream->player_id;
        }

        $candidates = [];
        foreach ($stream->transcription_speakers ?? [] as $voice) {
            $speaker = $voice['speaker'];
            if ($speaker === 0 || $names->has($speaker) || isset($decided[$speaker]) || ! $this->usable($voice)) {
                continue;
            }
            $embedding = self::normalise($voice['embedding']);
            $scores = collect($profiles)->except($taken)->map(fn (array $profile) => self::dot($embedding, $profile['embedding']))->sortDesc();
            foreach ($scores as $playerId => $similarity) {
                $candidates[] = ['speaker' => $speaker, 'player_id' => $playerId, 'similarity' => $similarity, 'margin' => $similarity - ($scores->except($playerId)->first() ?? -1)];
            }
        }

        // Greedy: the most similar pairs first, each speaker and player once.
        $matches = [];
        foreach (collect($candidates)->sortByDesc('similarity') as $candidate) {
            if (isset($matches[$candidate['speaker']]) || in_array($candidate['player_id'], $taken, true)) {
                continue;
            }
            if ($candidate['similarity'] < config('services.voices.match_threshold') || $candidate['margin'] < config('services.voices.match_margin')) {
                continue;
            }
            $matches[$candidate['speaker']] = ['player_id' => $candidate['player_id'], 'similarity' => round($candidate['similarity'], 3)];
            $taken[] = $candidate['player_id'];
        }

        return $matches;
    }

    /**
     * Every voice known to belong to a player: [player id, normalised embedding, stream id, confirmed by hand].
     * A speaker recognised by text counts too (not as confirmed by hand).
     *
     * @return \Generator<int, array{0: int, 1: list<float>, 2: int, 3: bool}>
     */
    public function knownVoices(): \Generator
    {
        $players = Player::query()->pluck('id')->flip();
        $names = StreamSpeaker::query()->get()->groupBy('stream_id');
        $textRows = SpeakerTextMatch::query()->get()->groupBy('stream_id');
        $streams = Stream::query()->whereNotNull('transcription_speakers')->select(['id', 'player_id', 'transcription_speakers'])->lazyById(50);

        foreach ($streams as $stream) {
            $streamNames = ($names->get($stream->id) ?? collect())->keyBy('speaker');
            $byText = $textRows->has($stream->id) ? $this->texts->matches($stream, $textRows->get($stream->id), $streamNames) : [];
            foreach ($stream->transcription_speakers as $voice) {
                if (! $this->usable($voice)) {
                    continue;
                }
                $name = $streamNames->get($voice['speaker']);
                $playerId = $name !== null ? $name->player_id : ($byText[$voice['speaker']]['player_id'] ?? ($voice['speaker'] === 0 ? $stream->player_id : null));
                if ($playerId !== null && $players->has($playerId)) {
                    yield [$playerId, self::normalise($voice['embedding']), $stream->id, $name !== null];
                }
            }
        }
    }

    /** @param array{seconds?: float, embedding?: list<float>|null} $voice */
    private function usable(array $voice): bool
    {
        return is_array($voice['embedding'] ?? null) && $voice['embedding'] !== [] && ($voice['seconds'] ?? 0) >= config('services.voices.min_seconds');
    }

    /** @param list<float> $vector @return list<float> */
    public static function normalise(array $vector): array
    {
        $length = sqrt(array_sum(array_map(fn ($value) => $value * $value, $vector)));

        return $length > 0 ? array_map(fn ($value) => $value / $length, $vector) : $vector;
    }

    /** Cosine similarity of two normalised vectors. */
    public static function dot(array $a, array $b): float
    {
        $sum = 0.0;
        foreach ($a as $index => $value) {
            $sum += $value * ($b[$index] ?? 0.0);
        }

        return $sum;
    }
}
