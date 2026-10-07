<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The activity log; the route only lets the admin in (EnsureUserIsAdmin). */
class ActivityLogController extends Controller
{
    public function index(Request $request): Response
    {
        $username = $request->string('user')->trim()->value();
        $entries = ActivityLog::query()
            ->when($username !== '', fn ($query) => $query->where('username', $username))
            ->latest('updated_at')->latest('id')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Activity', [
            'entries' => collect($entries->items())->map(fn (ActivityLog $entry) => [
                'id' => $entry->id,
                'at' => $entry->updated_at?->toIso8601String(),
                'username' => $entry->username,
                'description' => $entry->description,
                'subject_type' => $entry->subject_type,
                'subject_id' => $entry->subject_id,
                'subject_label' => $entry->subject_label,
                'result' => $entry->result,
                'succeeded' => $entry->succeeded,
                'count' => $entry->count,
                'ip_address' => $entry->ip_address,
            ]),
            'pagination' => ['current' => $entries->currentPage(), 'last' => $entries->lastPage(), 'total' => $entries->total()],
            'usernames' => ActivityLog::query()->whereNotNull('username')->distinct()->orderBy('username')->pluck('username'),
            'filter' => ['user' => $username],
        ]);
    }
}
