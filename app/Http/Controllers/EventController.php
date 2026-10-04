<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Inertia\Inertia;
use Inertia\Response;

class EventController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Events/Index', [
            'events' => Event::query()
                ->with('stream:id,title')
                ->withCount('transcriptSegments')
                ->orderByDesc('start_time')
                ->get()
                ->map(fn (Event $event) => [
                    'id' => $event->id,
                    'stream' => $event->stream,
                    'type' => $event->type,
                    'title' => $event->title,
                    'description' => $event->description,
                    'start_time' => (float) $event->start_time,
                    'end_time' => (float) $event->end_time,
                    'confidence' => (float) $event->confidence,
                    'segment_count' => $event->transcript_segments_count,
                ]),
        ]);
    }
}
