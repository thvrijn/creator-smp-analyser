<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Player;
use App\Models\Stream;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The search field in the top bar: players, streams and events whose name or title contains the term. */
class SearchController extends Controller
{
    private const LIMIT = 5;

    public function __invoke(Request $request): JsonResponse
    {
        $term = mb_substr(trim((string) $request->query('q', '')), 0, 100);
        if (mb_strlen($term) < 2) {
            return response()->json(['players' => [], 'streams' => [], 'events' => []]);
        }
        $like = '%'.addcslashes($term, '%_\\').'%';

        return response()->json([
            'players' => Player::query()->where('name', 'ilike', $like)->orderByRaw('lower(name)')->limit(self::LIMIT)
                ->get(['id', 'name', 'photo_path', 'updated_at'])
                ->map(fn (Player $player) => ['id' => $player->id, 'name' => $player->name, 'photo_url' => $player->photoUrl()]),
            'streams' => Stream::query()->with('player:id,name')->where('title', 'ilike', $like)->latest('started_at')->limit(self::LIMIT)
                ->get(['id', 'player_id', 'title', 'started_at'])
                ->map(fn (Stream $stream) => ['id' => $stream->id, 'title' => $stream->title, 'player' => $stream->player->name, 'started_at' => $stream->started_at?->toIso8601String()]),
            'events' => Event::query()->with('stream:id,player_id,title', 'stream.player:id,name')
                ->where(fn ($query) => $query->where('title', 'ilike', $like)->orWhere('description', 'ilike', $like))
                ->latest('id')->limit(self::LIMIT)
                ->get(['id', 'stream_id', 'type', 'title', 'start_time'])
                ->map(fn (Event $event) => ['id' => $event->id, 'stream_id' => $event->stream_id, 'type' => $event->type, 'title' => $event->title, 'player' => $event->stream->player->name, 'start_time' => (float) $event->start_time]),
        ]);
    }
}
