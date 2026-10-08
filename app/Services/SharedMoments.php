<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Player;
use App\Models\Stream;
use Illuminate\Support\Collection;

/**
 * Links an event to the same moment in other players' streams. Every streamer streams their own POV, so the moment
 * two players meet is in both streams at the same server time (stream start + video offset + time in the file).
 *
 * Being live at the same time is not enough (most of the ~90 players are), so another stream is only linked when that
 * player is in the moment: their voice is heard in the event's segments (a speaker named after them or recognised by
 * voice) or the event names them, or the other way round, an event in their stream at that time has this stream's
 * player's voice or names them.
 */
class SharedMoments
{
    /** Two events count as the same moment when they are this close in server time. */
    public const TOLERANCE_SECONDS = 30;

    /** Shorter names match too much by accident in titles. */
    private const MIN_NAME_LENGTH = 3;

    public function __construct(private readonly StreamSpeakers $speakers) {}

    /**
     * @return array<int, list<array{stream_id: int, stream_title: string, player: array{id: int, name: string, photo_url: ?string}, event_id: ?int, event_title: ?string, at: float, reasons: list<string>}>> per event id of $stream
     */
    public function forStream(Stream $stream): array
    {
        $events = $stream->events()->with('transcriptSegments:id,speaker')->orderBy('start_time')->get();
        if ($events->isEmpty()) {
            return [];
        }

        $players = Player::query()->get(['id', 'name', 'photo_path', 'updated_at'])->keyBy('id');
        $patterns = $this->namePatterns($players);
        $presence = $this->presence($stream, $events, $patterns);
        $base = $this->base($stream);
        $from = $base + (float) $events->min('start_time') - self::TOLERANCE_SECONDS;
        $until = $base + (float) $events->max('end_time') + self::TOLERANCE_SECONDS;

        $links = [];
        foreach ($this->streamsBetween($stream, $from, $until) as $other) {
            $otherBase = $this->base($other);
            $otherEvents = $other->events()->with('transcriptSegments:id,speaker')->orderBy('start_time')->get()
                ->filter(fn (Event $event) => $otherBase + (float) $event->end_time >= $from && $otherBase + (float) $event->start_time <= $until)
                ->values();
            $otherPresence = $otherEvents->isEmpty() ? [] : $this->presence($other, $otherEvents, $patterns);

            foreach ($events as $event) {
                $start = $base + (float) $event->start_time;
                $end = $base + (float) $event->end_time;
                if (! $this->covers($other, $start, $end)) {
                    continue;
                }
                // The other player is in this event…
                $reasons = $presence[$event->id][$other->player_id] ?? [];
                // …or this stream's player is in an event of theirs at the same time.
                $match = null;
                $bestOverlap = -INF;
                foreach ($otherEvents as $otherEvent) {
                    $overlap = min($end, $otherBase + (float) $otherEvent->end_time) - max($start, $otherBase + (float) $otherEvent->start_time);
                    if ($overlap < -self::TOLERANCE_SECONDS) {
                        continue;
                    }
                    $theirs = $otherPresence[$otherEvent->id][$stream->player_id] ?? [];
                    if ($reasons === [] && $theirs === []) {
                        continue;
                    }
                    if ($overlap > $bestOverlap) {
                        $bestOverlap = $overlap;
                        $match = [$otherEvent, $theirs];
                    }
                }
                if ($reasons === [] && $match === null) {
                    continue;
                }

                $player = $players->get($other->player_id);
                $links[$event->id][] = [
                    'stream_id' => $other->id,
                    'stream_title' => $other->title,
                    'player' => ['id' => $player->id, 'name' => $player->name, 'photo_url' => $player->photoUrl()],
                    'event_id' => $match[0]->id ?? null,
                    'event_title' => $match[0]->title ?? null,
                    // Where the moment starts in the other stream's media file (its transcript and event times).
                    'at' => round(max(0.0, $match !== null ? (float) $match[0]->start_time : $start - $otherBase), 3),
                    'reasons' => array_values(array_unique([
                        ...$reasons,
                        ...array_map(fn (string $reason) => $reason.'_there', $match[1] ?? []),
                    ])),
                ];
            }
        }

        return $links;
    }

    /**
     * Which other players are in each event, and how we know: 'voice' (a speaker in its segments is them) and/or
     * 'named' (its title or description names them). The stream's own player is left out.
     *
     * @param  Collection<int, Event>  $events
     * @param  array<int, string>  $patterns
     * @return array<int, array<int, list<string>>> per event id, per player id
     */
    private function presence(Stream $stream, Collection $events, array $patterns): array
    {
        $speakerPlayers = $this->speakers->players($stream);
        $presence = [];
        foreach ($events as $event) {
            foreach ($event->transcriptSegments as $segment) {
                $playerId = $segment->speaker === null ? null : ($speakerPlayers[$segment->speaker] ?? null);
                if ($playerId !== null && $playerId !== $stream->player_id) {
                    $presence[$event->id][$playerId][] = 'voice';
                }
            }
            $text = $event->title.' '.$event->description;
            foreach ($patterns as $playerId => $pattern) {
                if ($playerId !== $stream->player_id && preg_match($pattern, $text) === 1) {
                    $presence[$event->id][$playerId][] = 'named';
                }
            }
        }

        return array_map(fn (array $players) => array_map(fn (array $reasons) => array_values(array_unique($reasons)), $players), $presence);
    }

    /** @return array<int, string> a whole-word, case-insensitive pattern per player id */
    private function namePatterns(Collection $players): array
    {
        return $players
            ->filter(fn (Player $player) => mb_strlen(trim($player->name)) >= self::MIN_NAME_LENGTH)
            ->map(fn (Player $player) => '/(?<![\p{L}\p{N}_])'.preg_quote(trim($player->name), '/').'(?![\p{L}\p{N}_])/iu')
            ->all();
    }

    /** @return Collection<int, Stream> other players' streams that were on air between the two server times */
    private function streamsBetween(Stream $stream, float $from, float $until): Collection
    {
        return Stream::query()
            ->where('player_id', '!=', $stream->player_id)
            ->where('started_at', '<=', date(DATE_ATOM, (int) ceil($until)))
            ->where(fn ($query) => $query->whereNull('ended_at')->orWhere('ended_at', '>=', date(DATE_ATOM, (int) floor($from))))
            ->orderBy('started_at')
            ->get();
    }

    /** Whether the other stream was on air during the moment (with some slack at the edges). */
    private function covers(Stream $other, float $start, float $end): bool
    {
        $otherStart = $other->started_at->getTimestamp();
        // Without an end it is still live.
        $otherEnd = $other->ended_at?->getTimestamp() ?? now()->getTimestamp();

        return $start <= $otherEnd + self::TOLERANCE_SECONDS && $end >= $otherStart - self::TOLERANCE_SECONDS;
    }

    /** Server time (Unix seconds) of time 0 in the stream's media file. */
    private function base(Stream $stream): float
    {
        return $stream->started_at->getTimestamp() + (float) ($stream->video_offset_seconds ?? 0);
    }
}
