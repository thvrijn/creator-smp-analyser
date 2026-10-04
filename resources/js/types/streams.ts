export type PlayerOption = { id: number; name: string };

export type PlayerStats = PlayerOption & {
    streams_count: number;
    transcribed_streams_count: number;
    active_streams_count: number;
    events_count: number;
    last_stream_at: string | null;
};

export type TranscriptionState = 'pending' | 'queued' | 'processing' | 'completed' | 'failed';
export type TranscriptionStage = 'not_started' | 'queued' | 'extracting_audio' | 'transcribing' | 'completed' | 'failed';
export type EventExtractionStatus = 'pending' | 'queued' | 'processing' | 'completed' | 'failed';

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
};

export type Stream = {
    id: number;
    title: string;
    player: PlayerOption;
    started_at: string;
    ended_at: string | null;
    source: string | null;
    video_path: string | null;
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
    ? new Intl.DateTimeFormat('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(value))
    : '—';
