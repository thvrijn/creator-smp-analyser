import { router } from '@inertiajs/vue3';
import type { EventExtractionStatus, Stream, TranscriptionStatus } from '../types/streams';

export const transcriptionLabel = (status: Stream['transcription_status']) => ({ pending: 'Not transcribed', queued: 'Queued', processing: 'Processing', completed: 'Completed', failed: 'Failed' }[status]);
export const eventExtractionLabel = (status: EventExtractionStatus) => ({ pending: 'Not started', queued: 'Queued', processing: 'Extracting', completed: 'Completed', failed: 'Failed' }[status]);
export const stageLabel = (stage: Stream['transcription_stage']) => ({ not_started: 'Not started', queued: 'Queued', extracting_audio: 'Extracting audio', transcribing: 'Transcribing', completed: 'Completed', failed: 'Failed' }[stage]);
export const isTranscriptionActive = (stream: Stream) => stream.transcription_status === 'queued' || stream.transcription_status === 'processing';
export const isEventExtractionActive = (stream: Stream) => stream.event_extraction_status === 'queued' || stream.event_extraction_status === 'processing';
// Queued uses the same badge colour as processing.
export const eventBadgeClass = (stream: Stream) => 'transcription-' + (stream.event_extraction_status === 'queued' ? 'processing' : stream.event_extraction_status);

export const transcribeButtonLabel = (stream: Stream) => stream.transcription_status === 'queued' ? 'Queued…' : stream.transcription_status === 'processing' ? 'Processing…' : 'Transcribe';
export const extractButtonLabel = (stream: Stream) => stream.event_extraction_status === 'queued' ? 'Queued…' : stream.event_extraction_status === 'processing' ? 'Extracting…' : 'Extract events';

export const transcribe = (stream: Stream) => { router.post('/streams/' + stream.id + '/transcribe', {}, { preserveScroll: true }); };
export const extractEvents = (stream: Stream) => { router.post('/streams/' + stream.id + '/extract-events', {}, { preserveScroll: true }); };

export const formatSeconds = (seconds: number | null) => {
    if (seconds === null || !Number.isFinite(seconds)) return '—';
    const total = Math.max(0, Math.floor(seconds));
    return [Math.floor(total / 3600), Math.floor((total % 3600) / 60), total % 60].map((value) => String(value).padStart(2, '0')).join(':');
};

/** Fetches the live status of a stream and copies it onto the stream object. */
export const refreshStatus = async (stream: Stream): Promise<void> => {
    try {
        const response = await fetch('/streams/' + stream.id + '/transcription-status', { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        if (!response.ok) return;
        const status = await response.json() as TranscriptionStatus;
        Object.assign(stream, { transcription_status: status.status, transcription_stage: status.stage, transcription_progress: status.progress, transcription_processed_seconds: status.processed_seconds, transcription_duration_seconds: status.duration_seconds, transcription_segment_count: status.segment_count, transcription_started_at: status.started_at, transcription_eta_seconds: status.eta_seconds, transcription_error: status.error, event_extraction_status: status.event_extraction_status, event_extraction_error: status.event_extraction_error });
        if (status.status === 'completed') stream.has_transcript = stream.has_transcript || status.segment_count > 0;
    } catch { /* polling can retry on the next interval */ }
};
