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
        'chunk_seconds' => (float) env('EVENT_CHUNK_SECONDS', 90),
        'overlap_seconds' => (float) env('EVENT_CHUNK_OVERLAP_SECONDS', 15),
    ],
];
