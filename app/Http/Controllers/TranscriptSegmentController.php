<?php

namespace App\Http\Controllers;

use App\Models\TranscriptSegment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Corrects the text of one transcript line; what speech recognition wrote is kept in original_text. */
class TranscriptSegmentController extends Controller
{
    public function update(Request $request, TranscriptSegment $segment): RedirectResponse
    {
        $text = trim((string) $request->validate([
            'text' => ['required', 'string', 'max:2000'],
        ])['text']);
        if ($text === '') {
            return back()->withErrors(['text' => 'Een zin kan niet leeg zijn.']);
        }

        $original = $segment->original_text ?? $segment->text;
        $segment->update([
            'text' => $text,
            // Back to the recognised text: no longer a correction.
            'original_text' => $text === $original ? null : $original,
        ]);

        return back()->with('success', $segment->original_text === null ? 'De zin is weer zoals hij herkend werd.' : 'De zin is aangepast.');
    }
}
