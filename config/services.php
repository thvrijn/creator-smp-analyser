<?php

return [
    'transcription_worker' => [
        'url' => env('TRANSCRIPTION_WORKER_URL', 'http://worker:8001'),
        'timeout' => (int) env('TRANSCRIPTION_WORKER_TIMEOUT', 3600),
    ],
    'event_worker' => [
        'url' => env('EVENT_WORKER_URL', env('TRANSCRIPTION_WORKER_URL', 'http://worker:8001')),
        'timeout' => (int) env('EVENT_WORKER_TIMEOUT', 1800),
        'model' => env('EVENT_MODEL', 'unsloth/Qwen3-8B-bnb-4bit'),
        'device' => env('EVENT_DEVICE', 'cuda'),
        'quantization' => env('EVENT_QUANTIZATION', '4bit'),
        'max_tokens' => (int) env('EVENT_MAX_TOKENS', 768),
        'max_segments' => (int) env('EVENT_MAX_SEGMENTS', 12),
        'chunk_seconds' => (float) env('EVENT_CHUNK_SECONDS', 90),
        'overlap_seconds' => (float) env('EVENT_CHUNK_OVERLAP_SECONDS', 15),
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
