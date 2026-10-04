<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePlayerRequest;
use App\Http\Requests\UpdatePlayerRequest;
use App\Http\Resources\StreamResource;
use App\Models\Player;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PlayerController extends Controller
{
    public function index(): Response
    {
        $players = Player::query()
            ->withCount('streams')
            ->orderBy('name')
            ->get()
            ->map(fn (Player $player) => [
                'id' => $player->id,
                'name' => $player->name,
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
            'players' => Player::query()->withStreamStats()->orderBy('name')->get()->map->statsPayload()->values(),
        ]);
    }

    public function show(Player $player): Response
    {
        $player = Player::query()->withStreamStats()->findOrFail($player->id);
        $streams = $player->streams()->with('player:id,name')->withCount('transcriptSegments')->latest('started_at')->get();

        return Inertia::render('Players/Show', [
            'player' => $player->statsPayload(),
            'streams' => StreamResource::collection($streams)->resolve(),
            'players' => Player::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StorePlayerRequest $request): RedirectResponse
    {
        Player::create($request->validated());

        return redirect()->route('players.index')->with('success', 'Player added successfully.');
    }

    public function update(UpdatePlayerRequest $request, Player $player): RedirectResponse
    {
        $player->update($request->validated());

        return redirect()->route('players.index')->with('success', 'Player updated successfully.');
    }

    public function destroy(Player $player): RedirectResponse
    {
        if ($player->streams()->exists()) {
            return redirect()->route('players.index')->with('error', sprintf(
                'Cannot delete %s because this player has streams. Remove or reassign the streams first.',
                $player->name,
            ));
        }

        $player->delete();

        return redirect()->route('players.index')->with('success', 'Player deleted successfully.');
    }
}
