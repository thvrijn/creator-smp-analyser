<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreStreamRequest;
use App\Http\Resources\StreamResource;
use App\Jobs\ExtractStreamEventsJob;
use App\Jobs\TranscribeStreamJob;
use App\Models\Event;
use App\Models\Player;
use App\Models\Stream;
use App\Services\SharedMoments;
use App\Services\StreamJobCanceller;
use App\Services\StreamSpeakers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StreamController extends Controller
{
    public function index(): Response
    {
        $streams = Stream::query()->with(['player:id,name,photo_path,twitch_login,updated_at', 'activeWorker:id,name,current_stream_id'])->withCount('transcriptSegments')->latest('started_at')->get();

        return Inertia::render('Streams/Index', [
            'streams' => StreamResource::collection($streams)->resolve(),
            'players' => Player::query()->orderByName()->get(['id', 'name']),
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
                        throw new RuntimeException('De streamvideo kon niet worden opgeslagen.');
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

        return $this->backToStreams()->with('success', 'Stream toegevoegd.');
    }

    public function destroy(Stream $stream): RedirectResponse
    {
        $disk = Storage::disk(config('filesystems.default'));

        if ($stream->video_path !== null && ! $disk->delete($stream->video_path)) {
            throw new RuntimeException('De streamvideo kon niet worden verwijderd.');
        }

        $stream->delete();

        return $this->backToStreams()->with('success', 'Stream verwijderd.');
    }

    public function transcribe(Stream $stream): RedirectResponse
    {
        if (blank($stream->video_path)) {
            return $this->backToStreams()->with('error', 'Deze stream heeft geen videobestand.');
        }
        if (in_array($stream->transcription_status, ['queued', 'waiting', 'processing'], true) && ! $stream->isStalled('transcription_status')) {
            return $this->backToStreams()->with('error', 'Deze stream staat al in de wachtrij of wordt al getranscribeerd.');
        }
        // A new transcript replaces the segments a running analysis is linking its events to.
        if (in_array($stream->event_extraction_status, ['queued', 'waiting', 'processing'], true) && ! $stream->isStalled('event_extraction_status')) {
            return $this->backToStreams()->with('error', 'Deze stream wordt nog geanalyseerd. Transcribeer opnieuw als de analyse klaar is.');
        }
        if (! Storage::disk(config('filesystems.default'))->exists($stream->video_path)) {
            return $this->backToStreams()->with('error', 'De streamvideo bestaat niet.');
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
                'transcription_error' => 'De transcriptie kon niet aan de wachtrij worden toegevoegd: '.$exception->getMessage(),
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
            'event_extraction_progress' => $stream->eventExtractionProgress(),
            'event_extraction_chunks_done' => $stream->event_extraction_chunks_done,
            'event_extraction_chunks_total' => $stream->event_extraction_chunks_total,
            'event_extraction_eta_seconds' => $stream->estimatedEventExtractionEta(),
            'transcription_stalled' => $stream->isStalled('transcription_status'),
            'event_extraction_stalled' => $stream->isStalled('event_extraction_status'),
            'worker_name' => $stream->activeWorker?->name,
            'transcription_cancelling' => $stream->transcription_cancel_requested_at !== null,
            'event_extraction_cancelling' => $stream->event_extraction_cancel_requested_at !== null,
        ]);
    }

    public function extractEvents(Stream $stream): RedirectResponse
    {
        if ($stream->transcription_status !== 'completed' || $stream->transcriptSegments()->doesntExist()) {
            return $this->backToStreams()->with('error', 'Complete transcriptie is nodig voordat events kunnen worden geëxtraheerd.');
        }
        if (in_array($stream->event_extraction_status, ['queued', 'waiting', 'processing'], true) && ! $stream->isStalled('event_extraction_status')) {
            return $this->backToStreams()->with('error', 'Event-extractie staat al in de wachtrij of is bezig voor deze stream.');
        }

        try {
            $stream->forceFill([
                'event_extraction_status' => 'queued',
                'event_extraction_error' => null,
                'event_extraction_chunks_done' => 0,
                'event_extraction_chunks_total' => null,
                'event_extraction_started_at' => null,
                'event_extraction_completed_at' => null,
            ])->save();
            ExtractStreamEventsJob::dispatch($stream->id);
        } catch (\Throwable $exception) {
            $stream->forceFill([
                'event_extraction_status' => 'failed',
                'event_extraction_error' => 'De event-extractie kon niet aan de wachtrij worden toegevoegd: '.$exception->getMessage(),
            ])->save();

            return $this->backToStreams()->with('error', 'Event-extractie kon niet aan de wachtrij worden toegevoegd.');
        }

        return $this->backToStreams()->with('success', 'Event-extractie toegevoegd aan de wachtrij.');
    }

    /** Cancels the stream's transcription, analysis or audio download (task), queued or running. */
    public function cancel(Stream $stream, string $task, StreamJobCanceller $canceller): RedirectResponse
    {
        [$ok, $message] = $canceller->cancel($stream, $task);

        return $this->backToStreams()->with($ok ? 'success' : 'error', $message);
    }

    private const SEGMENTS_PER_PAGE = 50;

    /** The stream's media file for the player on the stream page; the response handles Range requests, so it can seek. */
    public function audio(Stream $stream): BinaryFileResponse
    {
        $disk = Storage::disk(config('filesystems.default'));
        abort_if(blank($stream->video_path) || ! $disk->exists($stream->video_path), 404);

        return response()->file($disk->path($stream->video_path), ['Content-Type' => $stream->video_mime_type ?: 'application/octet-stream']);
    }

    public function show(Request $request, Stream $stream): Response
    {
        $stream->load('player:id,name,photo_path,twitch_login,updated_at')->loadCount('transcriptSegments');
        $search = trim((string) $request->query('search', ''));
        $search = $search !== '' ? mb_substr($search, 0, 100) : null;

        $events = $stream->events()->with('transcriptSegments:id')->orderBy('start_time')->orderBy('id')->get();
        $selectedEvent = $request->filled('event') ? $events->firstWhere('id', (int) $request->query('event')) : null;
        abort_if($request->filled('event') && $selectedEvent === null, 404);

        $segmentsQuery = $stream->transcriptSegments()->orderBy('start_time')->orderBy('id');
        if ($search !== null) {
            $segmentsQuery->where('text', 'ilike', '%'.$search.'%');
        }
        // ?speaker=n: only what that speaker says (a click on their chip in the Transcript tab).
        $speaker = filter_var($request->query('speaker'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $speaker = $speaker === false ? null : $speaker;
        if ($speaker !== null) {
            $segmentsQuery->where('speaker', $speaker);
        }

        // Selecting an event opens the (unfiltered) transcript page that contains its first segment. ?at= (seconds in
        // the media file, from a moment linked in another stream) does the same for the segment playing at that time.
        $page = $request->integer('page', 1);
        $highlighted = $selectedEvent?->transcriptSegments->pluck('id')->sort()->values()->all() ?? [];
        if ($selectedEvent === null && $request->filled('at') && is_numeric($request->query('at'))) {
            $atSegment = $stream->transcriptSegments()->where('end_time', '>', (float) $request->query('at'))->orderBy('start_time')->orderBy('id')->first();
            $highlighted = $atSegment !== null ? [$atSegment->id] : [];
        }
        if ($search === null && $speaker === null && $highlighted !== [] && ! $request->has('page')) {
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
                // The storyline written by the analysis (what happened in the game).
                'story_summary' => $stream->story_summary,
                'story_players' => $stream->story_players ?? [],
                'story_parts' => $stream->story_parts ?? [],
            ],
            'speakers' => app(StreamSpeakers::class)->list($stream),
            // For naming a speaker after a player.
            'players' => Player::query()->orderByRaw('lower(name)')->get(['id', 'name']),
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
            // Per event: the same moment in other players' streams.
            'shared_moments' => (object) app(SharedMoments::class)->forStream($stream),
            'clips' => $stream->clips()->orderBy('start_seconds')->orderBy('id')->get()->map->payload()->values()->all(),
            'selected_event_id' => $selectedEvent?->id,
            'highlighted_segment_ids' => $highlighted,
            'segments' => [
                'data' => $paginator->getCollection()->map(fn ($segment) => [
                    'id' => $segment->id,
                    'start_time' => (float) $segment->start_time,
                    'end_time' => (float) $segment->end_time,
                    'text' => $segment->text,
                    'speaker' => $segment->speaker,
                    // Set when the text was corrected by hand: what speech recognition wrote.
                    'original_text' => $segment->original_text,
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
            'speaker_filter' => $speaker,
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
