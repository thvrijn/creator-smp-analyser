import { router } from '@inertiajs/vue3';
import type { EventExtractionStatus, Stream, TranscriptionStatus } from '../types/streams';

export const transcriptionLabel = (status: Stream['transcription_status']) => ({ pending: 'Niet getranscribeerd', queued: 'In wachtrij', waiting: 'Wacht op worker', processing: 'Bezig', completed: 'Klaar', failed: 'Mislukt' }[status]);
export const eventExtractionLabel = (status: EventExtractionStatus) => ({ pending: 'Niet gestart', queued: 'In wachtrij', waiting: 'Wacht op worker', processing: 'Bezig met analyseren', completed: 'Klaar', failed: 'Mislukt' }[status]);
export const stageLabel = (stage: Stream['transcription_stage']) => ({ not_started: 'Niet gestart', queued: 'In wachtrij', waiting_for_worker: 'Wacht op een vrije worker', downloading_audio: 'Audio naar worker', extracting_audio: 'Audio extraheren', transcribing: 'Transcriberen', diarizing: 'Sprekers herkennen', completed: 'Klaar', failed: 'Mislukt' }[stage]);
// Event types stay English keys (shared with the worker); 'other' is never shown.
export const eventTypeLabel = (type: string) => ({ player_encounter: 'Ontmoeting', conversation: 'Gesprek', combat: 'Gevecht', death: 'Dood', discovery: 'Ontdekking', item: 'Item', building: 'Bouwwerk', destruction: 'Vernieling', statement: 'Uitspraak' } as Record<string, string>)[type] ?? type.replace(/_/g, ' ');
const activeStatuses = ['queued', 'waiting', 'processing'];
// A stalled job (no progress for 10 minutes: killed by a restart or crash) is not active: its button starts it again.
export const isTranscriptionActive = (stream: Stream) => activeStatuses.includes(stream.transcription_status) && !stream.transcription_stalled;
export const isEventExtractionActive = (stream: Stream) => activeStatuses.includes(stream.event_extraction_status) && !stream.event_extraction_stalled;
export const stalledMessage = 'Lijkt vastgelopen: al 10 minuten geen voortgang.';
// Queued and waiting use the same badge colour as processing.
// Analysis progress: "stuk 34/142 · nog ~ 20:15"; empty until the job knows how many chunks there are.
export const eventProgressDetails = (stream: Stream) => [
    stream.event_extraction_chunks_total ? `stuk ${stream.event_extraction_chunks_done}/${stream.event_extraction_chunks_total}` : null,
    stream.event_extraction_eta_seconds !== null ? `nog ~ ${formatSeconds(stream.event_extraction_eta_seconds)}` : null,
].filter((part) => part !== null).join(' · ');
export const eventBadgeClass = (stream: Stream) => 'transcription-' + (['queued', 'waiting'].includes(stream.event_extraction_status) ? 'processing' : stream.event_extraction_status);
export const transcriptionBadgeClass = (stream: Stream) => 'transcription-' + (stream.transcription_status === 'waiting' ? 'queued' : stream.transcription_status);

export const transcribeButtonLabel = (stream: Stream) => stream.transcription_stalled ? 'Opnieuw starten' : stream.transcription_status === 'queued' ? 'In wachtrij…' : stream.transcription_status === 'waiting' ? 'Wacht op worker…' : stream.transcription_status === 'processing' ? 'Bezig…' : 'Transcriberen';
export const extractButtonLabel = (stream: Stream) => stream.event_extraction_stalled ? 'Opnieuw starten' : stream.event_extraction_status === 'queued' ? 'In wachtrij…' : stream.event_extraction_status === 'waiting' ? 'Wacht op worker…' : stream.event_extraction_status === 'processing' ? 'Analyseren…' : 'Analyseren';

export const transcribe = (stream: Stream) => { router.post('/streams/' + stream.id + '/transcribe', {}, { preserveScroll: true }); };
// A new transcript replaces the old one; the events stay but lose their link to the transcript.
export const retranscribe = (stream: Stream) => {
    if (window.confirm(`Transcript van "${stream.title}" opnieuw maken?\n\nHet huidige transcript wordt vervangen. De events blijven staan, maar zijn daarna niet meer aan het transcript gekoppeld: analyseer de stream daarna opnieuw.`)) transcribe(stream);
};
export const isDownloadActive = (stream: Stream) => (stream.video_download_status === 'queued' || stream.video_download_status === 'processing') && !stream.video_download_stalled;
export const downloadAudio = (stream: Stream) => { router.post('/streams/' + stream.id + '/download-audio', {}, { preserveScroll: true }); };
export const downloadLabel = (stream: Stream) => stream.video_download_status === 'queued' ? 'In wachtrij' : stream.video_download_progress >= 99 ? 'Bijna klaar…' : 'Downloaden ' + stream.video_download_progress + '%';
export const extractEvents = (stream: Stream) => { router.post('/streams/' + stream.id + '/extract-events', {}, { preserveScroll: true }); };

// "218 MB" / "1,2 GB", Dutch number format.
export const formatBytes = (bytes: number | null) => {
    if (bytes === null) return null;
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let value = bytes;
    let unit = 0;
    while (value >= 1000 && unit < units.length - 1) { value /= 1000; unit++; }
    return value.toLocaleString('nl-NL', { maximumFractionDigits: value < 10 && unit > 0 ? 1 : 0 }) + ' ' + units[unit];
};
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
        Object.assign(stream, { transcription_status: status.status, transcription_stage: status.stage, transcription_progress: status.progress, transcription_processed_seconds: status.processed_seconds, transcription_duration_seconds: status.duration_seconds, transcription_segment_count: status.segment_count, transcription_started_at: status.started_at, transcription_eta_seconds: status.eta_seconds, transcription_error: status.error, event_extraction_status: status.event_extraction_status, event_extraction_error: status.event_extraction_error, event_extraction_progress: status.event_extraction_progress, event_extraction_chunks_done: status.event_extraction_chunks_done, event_extraction_chunks_total: status.event_extraction_chunks_total, event_extraction_eta_seconds: status.event_extraction_eta_seconds, transcription_stalled: status.transcription_stalled, event_extraction_stalled: status.event_extraction_stalled, worker_name: status.worker_name });
        if (status.status === 'completed') stream.has_transcript = stream.has_transcript || status.segment_count > 0;
    } catch { /* polling can retry on the next interval */ }
};
