<?php

namespace App\Http\Controllers;

use App\Jobs\TranscribeStreamJob;
use App\Jobs\ExtractStreamEventsJob;
use App\Http\Requests\StoreStreamRequest;
use App\Http\Resources\StreamResource;
use App\Models\Event;
use App\Models\Player;
use App\Models\Stream;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Inertia\Inertia;
use Inertia\Response;

class StreamController extends Controller
{
    public function index(): Response
    {
        $streams = Stream::query()->with('player:id,name')->withCount('transcriptSegments')->latest('started_at')->get();

        return Inertia::render('Streams/Index', [
            'streams' => StreamResource::collection($streams)->resolve(),
            'players' => Player::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreStreamRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['source'] ??= '';
        $video = $request->file('video');
        $diskName = config('filesystems.default');
        $disk = Storage::disk($diskName);
        $videoPath = null;

        try {
            DB::transaction(function () use (&$videoPath, $data, $video, $diskName): void {
                $stream = Stream::create(collect($data)->except('video')->all());

                if ($video !== null) {
                    $videoPath = $video->store("streams/{$stream->id}/video", $diskName);

                    if ($videoPath === false) {
                        throw new RuntimeException('The stream video could not be stored.');
                    }

                    $stream->update([
                        'video_path' => $videoPath,
                        'video_original_filename' => mb_substr($video->getClientOriginalName(), 0, 255),
                        'video_mime_type' => $video->getMimeType(),
                        'video_file_size' => $video->getSize(),
                    ]);
                }
            });
        } catch (\Throwable $exception) {
            if ($videoPath !== null) {
                $disk->delete($videoPath);
            }

            throw $exception;
        }

        return $this->backToStreams()->with('success', 'Stream added successfully.');
    }

    public function destroy(Stream $stream): RedirectResponse
    {
        $disk = Storage::disk(config('filesystems.default'));

        if ($stream->video_path !== null && ! $disk->delete($stream->video_path)) {
            throw new RuntimeException('The stream video could not be deleted.');
        }

        $stream->delete();

        return $this->backToStreams()->with('success', 'Stream deleted successfully.');
    }

    public function transcribe(Stream $stream): RedirectResponse
    {
        if (blank($stream->video_path)) {
            return $this->backToStreams()->with('error', 'This stream has no video file.');
        }
        if (in_array($stream->transcription_status, ['queued', 'processing'], true)) {
            return $this->backToStreams()->with('error', 'This stream is already queued or being transcribed.');
        }
        if (! Storage::disk(config('filesystems.default'))->exists($stream->video_path)) {
            return $this->backToStreams()->with('error', 'The stream video does not exist.');
        }

        try {
            $stream->forceFill([
                'transcription_status' => 'queued',
                'transcription_error' => null,
                'transcribed_at' => null,
                'transcription_stage' => 'queued',
                'transcription_progress' => 0,
                'transcription_processed_seconds' => 0,
                'transcription_duration_seconds' => null,
                'transcription_segment_count' => 0,
                'transcription_started_at' => null,
                'transcription_eta_seconds' => null,
            ])->save();
            TranscribeStreamJob::dispatch($stream->id);
        } catch (\Throwable $exception) {
            $stream->forceFill([
                'transcription_status' => 'failed',
                'transcription_error' => 'The transcription job could not be queued: '.$exception->getMessage(),
            ])->save();

            return $this->backToStreams()->with('error', 'Transcriptie kon niet aan de wachtrij worden toegevoegd.');
        }

        return $this->backToStreams()->with('success', 'Transcriptie toegevoegd aan de wachtrij.');
    }

    public function transcriptionStatus(Stream $stream): JsonResponse
    {
        return response()->json([
            'status' => $stream->transcription_status,
            'stage' => $stream->transcription_stage,
            'progress' => max(0, min(100, (int) $stream->transcription_progress)),
            'processed_seconds' => (float) $stream->transcription_processed_seconds,
            'duration_seconds' => $stream->transcription_duration_seconds === null ? null : (float) $stream->transcription_duration_seconds,
            'segment_count' => (int) $stream->transcription_segment_count,
            'started_at' => $stream->transcription_started_at?->toIso8601String(),
            'eta_seconds' => $stream->estimatedTranscriptionEta(),
            'error' => $stream->transcription_error,
            'event_extraction_status' => $stream->event_extraction_status,
            'event_extraction_error' => $stream->event_extraction_error,
        ]);
    }

    public function extractEvents(Stream $stream): RedirectResponse
    {
        if ($stream->transcription_status !== 'completed' || $stream->transcriptSegments()->doesntExist()) {
            return $this->backToStreams()->with('error', 'Complete transcriptie is nodig voordat events kunnen worden geëxtraheerd.');
        }
        if (in_array($stream->event_extraction_status, ['queued', 'processing'], true)) {
            return $this->backToStreams()->with('error', 'Event extraction staat al in de wachtrij of is bezig voor deze stream.');
        }

        try {
            $stream->forceFill([
                'event_extraction_status' => 'queued',
                'event_extraction_error' => null,
                'event_extraction_started_at' => null,
                'event_extraction_completed_at' => null,
            ])->save();
            ExtractStreamEventsJob::dispatch($stream->id);
        } catch (\Throwable $exception) {
            $stream->forceFill([
                'event_extraction_status' => 'failed',
                'event_extraction_error' => 'The event extraction job could not be queued: '.$exception->getMessage(),
            ])->save();

            return $this->backToStreams()->with('error', 'Event extraction kon niet aan de wachtrij worden toegevoegd.');
        }

        return $this->backToStreams()->with('success', 'Event extraction toegevoegd aan de wachtrij.');
    }

    private const SEGMENTS_PER_PAGE = 50;

    public function show(Request $request, Stream $stream): Response
    {
        $stream->load('player:id,name')->loadCount('transcriptSegments');
        $search = trim((string) $request->query('search', ''));
        $search = $search !== '' ? mb_substr($search, 0, 100) : null;

        $events = $stream->events()->with('transcriptSegments:id')->orderBy('start_time')->orderBy('id')->get();
        $selectedEvent = $request->filled('event') ? $events->firstWhere('id', (int) $request->query('event')) : null;
        abort_if($request->filled('event') && $selectedEvent === null, 404);

        $segmentsQuery = $stream->transcriptSegments()->orderBy('start_time')->orderBy('id');
        if ($search !== null) {
            $segmentsQuery->where('text', 'ilike', '%'.$search.'%');
        }

        // Selecting an event opens the (unfiltered) transcript page that contains its first segment.
        $page = $request->integer('page', 1);
        $highlighted = $selectedEvent?->transcriptSegments->pluck('id')->sort()->values()->all() ?? [];
        if ($selectedEvent !== null && $search === null && $highlighted !== [] && ! $request->has('page')) {
            $first = $stream->transcriptSegments()->whereKey($highlighted)->orderBy('start_time')->orderBy('id')->first();
            $before = $stream->transcriptSegments()
                ->where(fn ($query) => $query->where('start_time', '<', $first->start_time)
                    ->orWhere(fn ($query) => $query->where('start_time', $first->start_time)->where('id', '<', $first->id)))
                ->count();
            $page = intdiv($before, self::SEGMENTS_PER_PAGE) + 1;
        }

        $paginator = $segmentsQuery->paginate(self::SEGMENTS_PER_PAGE, ['*'], 'page', $page)->withQueryString();
        $totalDuration = $stream->transcription_duration_seconds ?? $stream->transcriptSegments()->max('end_time');

        return Inertia::render('Streams/Show', [
            'stream' => [
                ...StreamResource::make($stream)->resolve(),
                'duration_seconds' => $totalDuration === null ? null : (float) $totalDuration,
                'segment_count' => $stream->transcript_segments_count,
            ],
            'events' => $events->map(fn (Event $event) => [
                'id' => $event->id,
                'type' => $event->type,
                'title' => $event->title,
                'description' => $event->description,
                'start_time' => (float) $event->start_time,
                'end_time' => (float) $event->end_time,
                'confidence' => (float) $event->confidence,
                'segment_count' => $event->transcriptSegments->count(),
            ])->values()->all(),
            'selected_event_id' => $selectedEvent?->id,
            'highlighted_segment_ids' => $highlighted,
            'segments' => [
                'data' => $paginator->getCollection()->map(fn ($segment) => [
                    'id' => $segment->id,
                    'start_time' => (float) $segment->start_time,
                    'end_time' => (float) $segment->end_time,
                    'text' => $segment->text,
                ])->values()->all(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'pagination' => collect($paginator->linkCollection())->map(fn (array $link) => [
                'url' => $link['url'],
                'label' => strip_tags($link['label']),
                'active' => $link['active'],
            ])->values()->all(),
            'search' => $search ?? '',
        ]);
    }

    /** The transcript used to be its own page; it now lives on the stream page. */
    public function transcript(Request $request, Stream $stream): RedirectResponse
    {
        return redirect()->to(route('streams.show', $stream).($request->getQueryString() ? '?'.$request->getQueryString() : ''), 301);
    }

    private function backToStreams(): RedirectResponse
    {
        return redirect()->back(fallback: route('streams.index'));
    }
}
