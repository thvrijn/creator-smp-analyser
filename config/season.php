<?php

// Creator SMP 4: three weeks of hardcore from 4 to 25 October 2026, and four recap episodes (one per week plus the
// finale). Dates are days in the server's time zone; an episode covers its days from start to end, both included.
return [
    'name' => 'Creator SMP 4',
    'timezone' => 'Europe/Amsterdam',
    'starts_on' => '2026-10-04',
    'ends_on' => '2026-10-25',
    // Words of the season Whisper does not know, given to it in one sentence with the player names (TranscriptionWorker::hotwords).
    'hotwords' => ['CreatorSMP', 'Verliezersrealm'],
    'episodes' => [
        ['title' => 'Week 1', 'from' => '2026-10-04', 'to' => '2026-10-10'],
        ['title' => 'Week 2', 'from' => '2026-10-11', 'to' => '2026-10-17'],
        ['title' => 'Week 3', 'from' => '2026-10-18', 'to' => '2026-10-24'],
        ['title' => 'Finale', 'from' => '2026-10-25', 'to' => '2026-10-25'],
    ],
];
