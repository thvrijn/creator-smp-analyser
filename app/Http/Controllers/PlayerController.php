<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePlayerRequest;
use App\Http\Requests\UpdatePlayerRequest;
use App\Http\Resources\StreamResource;
use App\Models\Event;
use App\Models\Player;
use App\Models\PlayerDeath;
use App\Models\Stream;
use App\Services\SharedMoments;
use App\Services\VodRetention;
use App\Services\VoiceProfiles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlayerController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Players/Index', [
            'players' => Player::query()->withStreamStats()->orderByName()->get()->map->statsPayload()->values(),
        ]);
    }

    public function show(Player $player, SharedMoments $moments, VodRetention $retention, VoiceProfiles $voices): Response
    {
        $player = Player::query()->withStreamStats()->withCount('clips')->findOrFail($player->id);
        $streams = $player->streams()
            ->with(['player:id,name,photo_path,twitch_login,twitch_broadcaster_type,updated_at', 'activeWorker:id,name,current_stream_id', 'events' => fn ($events) => $events->orderByDesc('confidence')->orderBy('start_time')])
            ->withCount(['transcriptSegments', 'events'])
            ->latest('started_at')
            ->get();

        // Per analysed stream the players met in it (SharedMoments: their voice or name in the same moment).
        $met = $streams->where('event_extraction_status', 'completed')
            ->mapWithKeys(fn (Stream $stream) => [$stream->id => collect($moments->forStream($stream))->map(fn (array $links) => collect($links)->pluck('player')->unique('id'))]);

        return Inertia::render('Players/Show', [
            'player' => [
                ...$player->statsPayload(),
                'clips_count' => (int) $player->clips_count,
                'retention_days' => config('services.twitch.vod_retention_days')[$player->twitch_broadcaster_type] ?? null,
                'deaths' => $player->deaths->map(fn (PlayerDeath $death) => ['id' => $death->id, 'died_at' => $death->died_at->toIso8601String(), 'message' => $death->message])->values(),
                'voice_streams' => $voices->profiles()[$player->id]['streams'] ?? 0,
            ],
            'streams' => StreamResource::collection($streams)->resolve(),
            // The story of each stream, by stream id: its storyline paragraphs, most confident events and the players met.
            'chapters' => $streams->mapWithKeys(fn (Stream $stream) => [$stream->id => [
                'paragraphs' => $stream->story_summary ? array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/', trim($stream->story_summary))))) : [],
                'events_count' => $stream->events_count,
                'events' => $stream->events->take(5)->sortBy('start_time')->map(fn (Event $event) => ['id' => $event->id, 'type' => $event->type, 'title' => $event->title])->values(),
                'met' => ($met[$stream->id] ?? collect())->flatten(1)->unique('id')->values(),
                'vod_expires_at' => $retention->expiresAt($stream)?->toIso8601String(),
            ]]),
            // Who this player shared the most moments with, over all their analysed streams.
            'met' => $met->flatMap(fn (Collection $perEvent, int $streamId) => $perEvent->flatten(1)->map(fn (array $other) => [...$other, 'stream_id' => $streamId]))
                ->groupBy('id')
                ->map(fn (Collection $shared) => [...collect($shared->first())->except('stream_id')->all(), 'moments' => $shared->count(), 'streams' => $shared->pluck('stream_id')->unique()->count()])
                ->sortByDesc('moments')->take(12)->values(),
            'players' => Player::query()->orderByName()->get(['id', 'name']),
        ]);
    }

    public function photo(Player $player): StreamedResponse
    {
        abort_if($player->photo_path === null || ! Storage::exists($player->photo_path), 404);

        // The URL carries ?v=updated_at, so a new photo gets a new URL.
        return Storage::response($player->photo_path, null, ['Cache-Control' => 'public, max-age=31536000, immutable']);
    }

    public function store(StorePlayerRequest $request): RedirectResponse
    {
        Player::create([
            'name' => $request->validated('name'),
            'twitch_login' => $request->validated('twitch_login'),
            'photo_path' => $request->file('photo')?->store('players'),
        ]);

        return redirect()->route('players.index')->with('success', 'Speler toegevoegd.');
    }

    public function update(UpdatePlayerRequest $request, Player $player): RedirectResponse
    {
        $oldPhoto = $player->photo_path;
        $data = $request->safe()->only(['name', 'twitch_login']);
        if ($request->hasFile('photo')) {
            $data['photo_path'] = $request->file('photo')->store('players');
        } elseif ($request->boolean('remove_photo')) {
            $data['photo_path'] = null;
        }
        $player->update($data);

        if ($oldPhoto !== null && $oldPhoto !== $player->photo_path) {
            Storage::delete($oldPhoto);
        }

        // Back to where the edit started: the players list or the player page.
        return redirect()->back(fallback: route('players.index'))->with('success', 'Speler bijgewerkt.');
    }

    public function destroy(Player $player): RedirectResponse
    {
        if ($player->streams()->exists()) {
            return redirect()->route('players.index')->with('error', sprintf(
                '%s kan niet worden verwijderd, want deze speler heeft streams. Verwijder de streams eerst of koppel ze aan een andere speler.',
                $player->name,
            ));
        }

        $player->delete();
        if ($player->photo_path !== null) {
            Storage::delete($player->photo_path);
        }

        return redirect()->route('players.index')->with('success', 'Speler verwijderd.');
    }
}
