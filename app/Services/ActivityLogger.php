<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Clip;
use App\Models\Player;
use App\Models\Stream;
use App\Models\TranscriptSegment;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/** Writes the activity log the admin reads on /activity. Never stores request input (passwords!). */
class ActivityLogger
{
    /** The same action on the same thing again within this time adds to the last entry (e.g. nudging a clip). */
    private const MERGE_SECONDS = 120;

    /** Route name => what the user did, in the UI's language. Routes not listed here are not logged. */
    public const ACTIONS = [
        'streams.store' => 'Stream toegevoegd',
        'streams.destroy' => 'Stream verwijderd',
        'streams.transcribe' => 'Transcriptie gestart',
        'streams.download-audio' => 'Audio ophalen gestart',
        'streams.extract-events' => 'Analyse gestart',
        'streams.cancel' => 'Job geannuleerd',
        'streams.speakers.update' => 'Spreker benoemd',
        'streams.speakers.merge' => 'Sprekers samengevoegd',
        'segments.speaker' => 'Spreker van een zin aangepast',
        'players.store' => 'Speler toegevoegd',
        'players.update' => 'Speler bewerkt',
        'players.destroy' => 'Speler verwijderd',
        'players.sync-vods' => "VOD's van alle spelers gesynct",
        'players.player-sync-vods' => "VOD's gesynct",
        'clips.store' => 'Clip gemaakt',
        'clips.update' => 'Clip aangepast',
        'clips.destroy' => 'Clip verwijderd',
        'workers.update' => 'Worker aan- of uitgezet',
        'workers.destroy' => 'Worker vergeten',
        'users.store' => 'Account aangemaakt',
        'users.destroy' => 'Account verwijderd',
        'profile.password' => 'Wachtwoord gewijzigd',
    ];

    private const CANCELLED_TASKS = [
        'transcription' => 'Transcriptie geannuleerd',
        'event_extraction' => 'Analyse geannuleerd',
        'video_download' => 'Audio ophalen geannuleerd',
    ];

    /** Logs a request to one of the ACTIONS routes, after it ran. */
    public function logRequest(Request $request, bool $succeeded, ?string $result): void
    {
        $route = $request->route();
        $action = $route?->getName();
        if ($action === null || ! isset(self::ACTIONS[$action])) {
            return;
        }

        $description = $action === 'streams.cancel'
            ? self::CANCELLED_TASKS[$route->parameter('task')] ?? self::ACTIONS[$action]
            : self::ACTIONS[$action];
        $subject = collect($route->parameters())->first(fn ($parameter) => $parameter instanceof Model);

        $this->log($request, $action, $description, $request->user(), $subject, $succeeded, $result);
    }

    public function log(Request $request, string $action, string $description, ?User $user, ?Model $subject = null, bool $succeeded = true, ?string $result = null, ?string $username = null): void
    {
        [$subjectType, $subjectId, $subjectLabel] = $this->subject($subject);
        $username ??= $user?->username;

        $previous = ActivityLog::query()
            ->where('action', $action)
            ->where('username', $username)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('succeeded', $succeeded)
            ->where('updated_at', '>=', now()->subSeconds(self::MERGE_SECONDS))
            ->latest('id')
            ->first();
        if ($previous !== null) {
            $previous->forceFill(['count' => $previous->count + 1, 'result' => $result ?? $previous->result])->save();

            return;
        }

        ActivityLog::create([
            'user_id' => $user?->id,
            'username' => $username,
            'action' => $action,
            'description' => $description,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'subject_label' => $subjectLabel,
            'result' => $result,
            'succeeded' => $succeeded,
            'ip_address' => $request->ip(),
        ]);
    }

    /** @return array{0: ?string, 1: ?int, 2: ?string} type (stream, player, ...), id and a readable name */
    private function subject(?Model $subject): array
    {
        return match (true) {
            $subject instanceof Stream => ['stream', $subject->id, $subject->title],
            $subject instanceof Player => ['player', $subject->id, $subject->name],
            // Clips and segments are shown by their stream, which is where they are made and edited.
            $subject instanceof Clip => ['stream', $subject->stream_id, $subject->stream?->title],
            $subject instanceof TranscriptSegment => ['stream', $subject->stream_id, $subject->stream?->title],
            $subject instanceof Worker => ['worker', $subject->id, $subject->name],
            $subject instanceof User => ['user', $subject->id, $subject->username],
            default => [null, null, null],
        };
    }
}
