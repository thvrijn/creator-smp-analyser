export type PlayerOption = { id: number; name: string };
export type PlayerRef = PlayerOption & { photo_url: string | null };

export type EditablePlayer = PlayerRef & { twitch_login: string | null };

export type PlayerStats = EditablePlayer & {
    vods_synced_at: string | null;
    streams_count: number;
    transcribed_streams_count: number;
    active_streams_count: number;
    events_count: number;
    last_stream_at: string | null;
};

export type TranscriptionState = 'pending' | 'queued' | 'waiting' | 'processing' | 'completed' | 'failed';
export type TranscriptionStage = 'not_started' | 'queued' | 'waiting_for_worker' | 'downloading_audio' | 'extracting_audio' | 'transcribing' | 'completed' | 'failed';
export type EventExtractionStatus = 'pending' | 'queued' | 'waiting' | 'processing' | 'completed' | 'failed';

export type TranscriptionStatus = {
    status: TranscriptionState;
    stage: TranscriptionStage;
    progress: number;
    processed_seconds: number;
    duration_seconds: number | null;
    segment_count: number;
    started_at: string | null;
    eta_seconds: number | null;
    error: string | null;
    event_extraction_status: EventExtractionStatus;
    event_extraction_error: string | null;
    transcription_stalled: boolean;
    event_extraction_stalled: boolean;
    worker_name: string | null;
};

export type Stream = {
    id: number;
    title: string;
    player: PlayerRef;
    started_at: string;
    ended_at: string | null;
    source: string | null;
    video_path: string | null;
    video_mime_type: string | null;
    twitch_video_id: string | null;
    video_download_status: 'pending' | 'queued' | 'processing' | 'completed' | 'failed';
    video_download_progress: number;
    video_download_error: string | null;
    transcription_stalled: boolean;
    event_extraction_stalled: boolean;
    video_download_stalled: boolean;
    // The worker running this stream's transcription or analysis right now.
    worker_name: string | null;
    video_offset_seconds: number;
    transcription_ranges: [number, number][] | null;
    transcription_status: TranscriptionState;
    transcription_stage: TranscriptionStage;
    transcription_progress: number;
    transcription_processed_seconds: number;
    transcription_duration_seconds: number | null;
    transcription_segment_count: number;
    transcription_started_at: string | null;
    transcription_eta_seconds: number | null;
    transcription_error: string | null;
    event_extraction_status: EventExtractionStatus;
    event_extraction_error: string | null;
    has_transcript: boolean;
    status: 'Live' | 'Finished';
};

export const formatDate = (value: string | null) => value
    ? new Intl.DateTimeFormat('nl-NL', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(value))
    : '—';
