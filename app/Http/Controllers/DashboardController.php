<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Player;
use App\Models\PlayerDeath;
use App\Models\Stream;
use App\Services\PipelineBacklog;
use App\Services\SharedMoments;
use App\Services\StreamPipeline;
use App\Services\VodRetention;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The start page: where the season is, what is left to do in the pipeline, what is new in the story, and how the teams
 * are doing. Running jobs are not here: the sidebar shows them on every page.
 */
class DashboardController extends Controller
{
    /** How many streams of a step, chapters and deaths the page lists. */
    private const LIST_LIMIT = 5;

    private const CHAPTERS = 6;

    private const DEATHS = 8;

    public function __construct(private readonly PipelineBacklog $backlog, private readonly VodRetention $retention) {}

    public function index(SharedMoments $moments): Response
    {
        $expiring = $this->backlog->expiring();
        $syncedAt = Player::query()->max('vods_synced_at');

        return Inertia::render('Dashboard', [
            'season' => $this->season(),
            'steps' => collect(PipelineBacklog::STEPS)->keys()->mapWithKeys(fn (string $step) => [$step => $this->step($step)]),
            'live_count' => Stream::query()->whereNotNull('twitch_video_id')->whereNull('ended_at')->count(),
            'vods_synced_at' => $syncedAt === null ? null : CarbonImmutable::parse($syncedAt)->toIso8601String(),
            'expiring' => $expiring->take(self::LIST_LIMIT * 2)->map(fn (Stream $stream) => [
                ...$this->streamPayload($stream),
                'expires_at' => $this->retention->expiresAt($stream)->toIso8601String(),
                'retention_days' => $this->retention->days($stream),
                'retention_known' => $this->retention->known($stream),
                'has_audio' => $stream->video_path !== null,
                'clips_count' => $stream->clips_count,
            ])->values(),
            'expiring_count' => $expiring->count(),
            'expired_without_audio' => $this->backlog->expiredWithoutAudio(),
            'chapters' => $this->chapters($moments),
            'teams' => $this->teams(),
            'deaths' => PlayerDeath::query()->with('player:id,name,photo_path,team,updated_at')->latest('died_at')->limit(self::DEATHS)->get()
                ->map(fn (PlayerDeath $death) => [
                    'id' => $death->id,
                    'died_at' => $death->died_at->toIso8601String(),
                    'message' => $death->message,
                    'player' => [...$this->playerPayload($death->player), 'team' => $death->player->team],
                ]),
        ]);
    }

    /** Queues the step for every stream that is ready for it (not the failed ones: they need a look first), oldest first. */
    public function queue(string $step, StreamPipeline $pipeline): RedirectResponse
    {
        $streams = $this->backlog->streams($step)->where(PipelineBacklog::STEPS[$step], 'pending');
        $queued = $streams->filter(fn (Stream $stream) => match ($step) {
            'download' => $pipeline->download($stream),
            'transcribe' => $pipeline->transcribe($stream),
            'extract' => $pipeline->extract($stream),
        } === null)->count();

        $back = back(fallback: route('dashboard'));
        if ($queued === 0) {
            return $back->with('error', 'Er stond niets klaar voor deze stap.');
        }
        $skipped = $streams->count() - $queued;

        return $back->with('success', ($queued === 1 ? '1 stream' : "{$queued} streams").' in de wachtrij gezet.'.($skipped ? " {$skipped} konden niet." : ''));
    }

    /** @return array<string, mixed> */
    private function season(): array
    {
        $zone = config('season.timezone');
        $today = CarbonImmutable::now($zone)->startOfDay();
        $start = CarbonImmutable::parse(config('season.starts_on'), $zone);
        $end = CarbonImmutable::parse(config('season.ends_on'), $zone);

        return [
            'name' => config('season.name'),
            'starts_on' => $start->toDateString(),
            'ends_on' => $end->toDateString(),
            'days' => (int) $start->diffInDays($end) + 1,
            // 0 before the season, days + 1 after it.
            'day' => (int) max(0, min($start->diffInDays($end) + 2, $start->diffInDays($today, false) + 1)),
            'episodes' => collect(config('season.episodes'))->map(fn (array $episode) => [
                ...$episode,
                'state' => match (true) {
                    $today->gt(CarbonImmutable::parse($episode['to'], $zone)) => 'done',
                    $today->lt(CarbonImmutable::parse($episode['from'], $zone)) => 'upcoming',
                    default => 'current',
                },
            ]),
        ];
    }

    /** @return array{ready: int, failed: int, streams: list<array<string, mixed>>} */
    private function step(string $step): array
    {
        $streams = $this->backlog->streams($step);
        $column = PipelineBacklog::STEPS[$step];
        $error = $step === 'extract' ? 'event_extraction_error' : ($step === 'download' ? 'video_download_error' : 'transcription_error');

        return [
            'ready' => $streams->where($column, 'pending')->count(),
            'failed' => $streams->where($column, 'failed')->count(),
            // The failed ones first: they need a look before anything else.
            'streams' => $streams->sortBy(fn (Stream $stream) => $stream->{$column} === 'failed' ? 0 : 1)->take(self::LIST_LIMIT)
                ->map(fn (Stream $stream) => [...$this->streamPayload($stream), 'failed' => $stream->{$column} === 'failed', 'error' => $stream->{$column} === 'failed' ? $stream->{$error} : null])
                ->values()->all(),
        ];
    }

    /**
     * The latest analysed streams as chapters of their player's story: the first paragraph of the storyline, the most
     * confident events, and the players met in them (SharedMoments).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function chapters(SharedMoments $moments): Collection
    {
        return Stream::query()
            ->with(['player:id,name,photo_path,updated_at', 'events' => fn ($events) => $events->orderByDesc('confidence')->orderBy('start_time')])
            ->withCount('events')
            ->where('event_extraction_status', 'completed')
            ->latest('started_at')
            ->limit(self::CHAPTERS)
            ->get()
            ->map(function (Stream $stream) use ($moments) {
                $met = collect($moments->forStream($stream))->flatten(1)->pluck('player')->unique('id')->values();

                return [
                    ...$this->streamPayload($stream),
                    'summary' => $stream->story_summary ? Str::limit(trim(Str::before(trim($stream->story_summary), "\n\n")), 320) : null,
                    'events_count' => $stream->events_count,
                    'events' => $stream->events->take(3)->map(fn (Event $event) => ['id' => $event->id, 'type' => $event->type, 'title' => $event->title])->values(),
                    'met' => $met,
                ];
            });
    }

    /** Per team: how many players it has and how many of them died at least once (a death in the grace period is not final). */
    private function teams(): Collection
    {
        $players = Player::query()->whereNotNull('team')->withCount('deaths')->get(['id', 'team']);

        return collect(Player::TEAMS)->map(fn (string $team) => [
            'name' => $team,
            'players' => $players->where('team', $team)->count(),
            'died' => $players->where('team', $team)->where('deaths_count', '>', 0)->count(),
        ]);
    }

    /** @return array<string, mixed> */
    private function streamPayload(Stream $stream): array
    {
        return [
            'id' => $stream->id,
            'title' => $stream->title,
            'started_at' => $stream->started_at?->toIso8601String(),
            'ended_at' => $stream->ended_at?->toIso8601String(),
            'player' => $this->playerPayload($stream->player),
        ];
    }

    /** @return array{id: int, name: string, photo_url: ?string} */
    private function playerPayload(Player $player): array
    {
        return ['id' => $player->id, 'name' => $player->name, 'photo_url' => $player->photoUrl()];
    }
}
