<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePlayerRequest;
use App\Http\Requests\UpdatePlayerRequest;
use App\Http\Resources\StreamResource;
use App\Models\Player;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlayerController extends Controller
{
    public function index(): Response
    {
        $players = Player::query()
            ->withCount('streams')
            ->orderByName()
            ->get()
            ->map(fn (Player $player) => [
                'id' => $player->id,
                'name' => $player->name,
                'photo_url' => $player->photoUrl(),
                'twitch_login' => $player->twitch_login,
                'streams_count' => $player->streams_count,
                'created_at' => $player->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Players/Index', [
            'players' => $players,
        ]);
    }

    public function dashboard(): Response
    {
        return Inertia::render('Dashboard', [
            'players' => Player::query()->withStreamStats()->orderByName()->get()->map->statsPayload()->values(),
        ]);
    }

    public function show(Player $player): Response
    {
        $player = Player::query()->withStreamStats()->findOrFail($player->id);
        $streams = $player->streams()->with(['player:id,name,photo_path,updated_at', 'activeWorker:id,name,current_stream_id'])->withCount('transcriptSegments')->latest('started_at')->get();

        return Inertia::render('Players/Show', [
            'player' => $player->statsPayload(),
            'streams' => StreamResource::collection($streams)->resolve(),
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
