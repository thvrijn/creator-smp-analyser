<?php

namespace App\Http\Controllers;

use App\Jobs\MatchSpeakerTextJob;
use App\Models\Stream;
use App\Models\TranscriptSegment;
use App\Services\StreamSpeakers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Corrects the diarized speakers on the stream page (see StreamSpeakers). */
class SpeakerController extends Controller
{
    public function __construct(private readonly StreamSpeakers $speakers) {}

    public function update(Request $request, Stream $stream, int $speaker): RedirectResponse
    {
        abort_unless(in_array($speaker, $this->speakers->numbers($stream), true), 404);
        $validated = $request->validate([
            'player_id' => ['nullable', 'integer', Rule::exists('players', 'id')],
            'label' => ['nullable', 'string', 'max:60'],
            'unknown' => ['sometimes', 'boolean'],
        ]);

        $before = $stream->speakerNames()->where('speaker', $speaker)->value('player_id');
        $this->speakers->name($stream, $speaker, $validated['player_id'] ?? null, $validated['label'] ?? null, (bool) ($validated['unknown'] ?? false));
        // Which speakers are the streamer decides what other streams' speakers are compared with (SpeakerTextMatches).
        if ($speaker === 0 || in_array($stream->player_id, [$before, $validated['player_id'] ?? null])) {
            MatchSpeakerTextJob::dispatch($stream->id);
        }
        $name = collect($this->speakers->list($stream))->firstWhere('speaker', $speaker)['name'];

        return back()->with('success', "Spreker {$speaker} is nu {$name}.");
    }

    public function merge(Request $request, Stream $stream, int $speaker): RedirectResponse
    {
        $numbers = $this->speakers->numbers($stream);
        abort_unless(in_array($speaker, $numbers, true), 404);
        $into = (int) $request->validate([
            'into' => ['required', 'integer', Rule::in($numbers), Rule::notIn([$speaker])],
        ])['into'];

        $names = collect($this->speakers->list($stream))->pluck('name', 'speaker');
        $this->speakers->merge($stream, $speaker, $into);
        // Speaker numbers changed, so the stored text matches are redone.
        MatchSpeakerTextJob::dispatch($stream->id);

        return back()->with('success', "{$names[$speaker]} is samengevoegd met {$names[$into]}.");
    }

    public function segment(Request $request, TranscriptSegment $segment): RedirectResponse
    {
        $speaker = $request->input('speaker');
        $valid = $speaker === null || $speaker === 'new'
            || (is_int($speaker) || ctype_digit((string) $speaker)) && in_array((int) $speaker, $this->speakers->numbers($segment->stream), true);
        if (! $valid) {
            return back()->withErrors(['speaker' => 'Deze spreker bestaat niet in deze stream.']);
        }

        $this->speakers->moveSegment($segment, $speaker === null || $speaker === 'new' ? $speaker : (int) $speaker);

        return back();
    }
}
