<?php

namespace App\Http\Controllers;

use App\Models\Clip;
use App\Models\Stream;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ClipController extends Controller
{
    /** The video builder: every clip of every player, in the order it happened on the server. */
    public function index(): Response
    {
        $clips = Clip::query()->with('stream.player:id,name,photo_path,updated_at')->get()
            ->sortBy(fn (Clip $clip) => $clip->stream->started_at->getTimestamp() + $clip->start_seconds)
            ->values();

        return Inertia::render('VideoBuilder', [
            'clips' => $clips->map(fn (Clip $clip) => [
                ...$clip->payload(),
                'starts_at' => $clip->stream->started_at->toImmutable()->addSeconds((int) $clip->start_seconds)->toIso8601String(),
                'stream' => ['id' => $clip->stream->id, 'title' => $clip->stream->title],
                'player' => ['id' => $clip->stream->player->id, 'name' => $clip->stream->player->name, 'photo_url' => $clip->stream->player->photoUrl()],
            ])->all(),
        ]);
    }

    public function store(Request $request, Stream $stream): RedirectResponse
    {
        $clip = $stream->clips()->create($request->validate([
            ...$this->rules(),
            'event_id' => ['nullable', 'integer', Rule::exists('events', 'id')->where('stream_id', $stream->id)],
        ]));

        // Open the new clip in the Clips tab, to watch and adjust it.
        return redirect()->to(route('streams.show', $stream).'?'.http_build_query(['tab' => 'clips', 'clip' => $clip->id]));
    }

    public function update(Request $request, Clip $clip): RedirectResponse
    {
        $clip->update($request->validate($this->rules()));

        return back(fallback: route('streams.show', $clip->stream_id));
    }

    public function destroy(Clip $clip): RedirectResponse
    {
        $clip->delete();

        return back(fallback: route('streams.show', $clip->stream_id))->with('success', 'Clip verwijderd.');
    }

    /** @return array<string, list<string>> */
    private function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'start_seconds' => ['required', 'numeric', 'min:0'],
            'end_seconds' => ['required', 'numeric', 'gt:start_seconds'],
        ];
    }
}
