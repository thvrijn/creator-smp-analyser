<?php

namespace App\Console\Commands;

use App\Services\VoiceProfiles;
use Illuminate\Console\Command;

/**
 * Measures how well voices separate players, to tune services.voices.match_threshold: compares every known voice
 * (speaker 0 of a player's own stream, or a speaker named by hand) with every known voice from another stream.
 */
class EvaluateVoices extends Command
{
    protected $signature = 'voices:evaluate';

    protected $description = 'Compare known voices across streams and show how well a similarity threshold separates players';

    public function handle(VoiceProfiles $profiles): int
    {
        $voices = iterator_to_array($profiles->knownVoices(), false);
        $same = [];
        $different = [];
        foreach ($voices as $i => [$playerA, $embeddingA, $streamA]) {
            foreach (array_slice($voices, $i + 1) as [$playerB, $embeddingB, $streamB]) {
                if ($streamA === $streamB) {
                    continue;
                }
                $similarity = VoiceProfiles::dot($embeddingA, $embeddingB);
                $playerA === $playerB ? $same[] = $similarity : $different[] = $similarity;
            }
        }

        $this->info(sprintf('%d known voices from %d players.', count($voices), count(array_unique(array_column($voices, 0)))));
        if ($same === [] || $different === []) {
            $this->warn('Need at least two streams of one player and voices of two players to measure. Transcribe more streams with diarization, or name speakers by hand.');

            return self::SUCCESS;
        }

        $this->table(['Pairs', 'Count', 'Min', 'Median', 'Max'], [
            ['same player', count($same), ...$this->stats($same)],
            ['different players', count($different), ...$this->stats($different)],
        ]);

        $rows = [];
        foreach (range(30, 85, 5) as $percent) {
            $threshold = $percent / 100;
            $missed = count(array_filter($same, fn ($value) => $value < $threshold)) / count($same);
            $wrong = count(array_filter($different, fn ($value) => $value >= $threshold)) / count($different);
            $rows[] = [number_format($threshold, 2), sprintf('%.0f%%', 100 * (1 - $missed)), sprintf('%.1f%%', 100 * $wrong)];
        }
        $this->table(['Threshold', 'Same player recognised', 'Different players mixed up'], $rows);
        $this->line('Current threshold: '.config('services.voices.match_threshold').' (VOICE_MATCH_THRESHOLD). Pick one that mixes up (almost) nobody.');

        return self::SUCCESS;
    }

    /** @param list<float> $values @return array{0: string, 1: string, 2: string} */
    private function stats(array $values): array
    {
        sort($values);

        return array_map(fn ($value) => number_format($value, 2), [$values[0], $values[intdiv(count($values), 2)], end($values)]);
    }
}
