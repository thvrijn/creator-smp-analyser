<?php

return [
    // Workers check in with a heartbeat (POST /api/workers/heartbeat) and the app picks a free one per job.
    'workers' => [
        // Shared secret: the workers send it to the app, and the app sends it to the workers.
        'token' => env('WORKER_TOKEN'),
        // How the workers reach the app, for the signed audio download URLs.
        'app_url' => env('WORKER_APP_URL', env('APP_URL', 'http://localhost:8000')),
        // A worker without a heartbeat for this long counts as offline (it sends one every 15 s).
        'online_seconds' => (int) env('WORKER_ONLINE_SECONDS', 45),
        // A job waits this long between checks for a free worker.
        'wait_seconds' => (int) env('WORKER_WAIT_SECONDS', 30),
    ],
    'transcription_worker' => [
        'timeout' => (int) env('TRANSCRIPTION_WORKER_TIMEOUT', 3600),
    ],
    // Recognising a speaker in another stream by comparing voice embeddings (App\Services\VoiceProfiles).
    // Measure with `php artisan voices:evaluate` once several players have diarized streams, and tune these.
    'voices' => [
        // Cosine similarity a speaker's voice needs with a player's profile to be shown as that player.
        'match_threshold' => (float) env('VOICE_MATCH_THRESHOLD', 0.6),
        // ...and how much better the best player must be than the next one.
        'match_margin' => (float) env('VOICE_MATCH_MARGIN', 0.05),
        // Shorter speakers have unreliable embeddings: not matched and not used in profiles.
        'min_seconds' => (float) env('VOICE_MIN_SECONDS', 20),
    ],

    'event_worker' => [
        'timeout' => (int) env('EVENT_WORKER_TIMEOUT', 1800),
        'model' => env('EVENT_MODEL', 'unsloth/Qwen3-8B-bnb-4bit'),
        'device' => env('EVENT_DEVICE', 'cuda'),
        'quantization' => env('EVENT_QUANTIZATION', '4bit'),
        'max_tokens' => (int) env('EVENT_MAX_TOKENS', 1024),
        // A story event is a happening, not one remark (min), and not a summary of the whole 5-minute part (max; a 5-minute part has ~150-250 lines).
        'min_segments' => (int) env('EVENT_MIN_SEGMENTS', 2),
        'max_segments' => (int) env('EVENT_MAX_SEGMENTS', 200),
        // Parts of 5 minutes: long enough for the model to see a whole happening, short enough for 8 GB of VRAM.
        'chunk_seconds' => (float) env('EVENT_CHUNK_SECONDS', 300),
        'overlap_seconds' => (float) env('EVENT_CHUNK_OVERLAP_SECONDS', 60),
    ],
    'twitch' => [
        'client_id' => env('TWITCH_CLIENT_ID'),
        'client_secret' => env('TWITCH_CLIENT_SECRET'),
        // VODs from before this date are not synced: the start of Creator SMP 4.
        'vods_since' => env('TWITCH_VODS_SINCE', '2026-10-04'),
        // Only the part of a VOD in this Twitch category, during the server's opening hours, is kept.
        'category' => env('TWITCH_CATEGORY', 'CreatorSMP'),
        'server_opens_hour' => (int) env('SMP_SERVER_OPENS_HOUR', 14),
        'server_closes_hour' => (int) env('SMP_SERVER_CLOSES_HOUR', 24), // 24 = midnight
        'server_timezone' => 'Europe/Amsterdam',
        // yt-dlp format for the analysis download: Twitch's audio-only rendition, else the smallest video rendition (it has audio too).
        'audio_format' => env('TWITCH_AUDIO_FORMAT', 'ba/worst'),
        // Used to check the free disk space before a download; Twitch's audio-only rendition is ~90 MB per hour.
        'download_gb_per_hour' => (float) env('TWITCH_DOWNLOAD_GB_PER_HOUR', 0.1),
    ],
];
