<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { streamDurationLabel, cancelTask, canCancel, isCancelling, downloadAudio, formatBytes, downloadLabel, eventBadgeClass, eventProgressDetails, isDownloadActive, retranscribe, eventExtractionLabel, extractButtonLabel, extractEvents, formatSeconds, isEventExtractionActive, isTranscriptionActive, refreshStatus, stageLabel, stalledMessage, transcribe, transcribeButtonLabel, transcriptionBadgeClass, transcriptionLabel } from '../composables/streamStatus';
import { type Stream } from '../types/streams';
import PlayerAvatar from './PlayerAvatar.vue';

const props = withDefaults(defineProps<{ streams: Stream[]; showPlayer?: boolean }>(), { showPlayer: true });
const streams = ref<Stream[]>(props.streams);
watch(() => props.streams, (value) => { streams.value = value; }, { deep: true });

const streamUrl = (stream: Stream) => '/streams/' + stream.id;
const removeStream = (stream: Stream) => { if (window.confirm('"' + stream.title + '" verwijderen?')) router.delete(streamUrl(stream), { preserveScroll: true }); };
// Start date, and the time range below it (or Live): one column instead of start, end and status.
const dayLabel = (value: string) => new Intl.DateTimeFormat('nl-NL', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(value));
const timeLabel = (value: string) => new Intl.DateTimeFormat('nl-NL', { hour: '2-digit', minute: '2-digit' }).format(new Date(value));
// The whole row opens the stream page; links and buttons inside it keep their own action.
const openStream = (event: MouseEvent, stream: Stream) => {
    if ((event.target as HTMLElement).closest('a, button, input, select')) return;
    router.visit(streamUrl(stream));
};
const displayEta = (stream: Stream) => {
    if (stream.transcription_eta_seconds !== null) return stream.transcription_eta_seconds;
    if (stream.transcription_status !== 'processing' || !stream.transcription_started_at || !stream.transcription_duration_seconds || stream.transcription_processed_seconds <= 0) return null;
    const elapsed = (Date.now() - new Date(stream.transcription_started_at).getTime()) / 1000;
    if (elapsed <= 0) return null;
    const rate = stream.transcription_processed_seconds / elapsed;
    return rate > 0 ? Math.max(0, (stream.transcription_duration_seconds - stream.transcription_processed_seconds) / rate) : null;
};

// The file may start later than the VOD (only the part when the server was open is kept).
const smpSeconds = (stream: Stream) => (stream.transcription_ranges ?? []).reduce((total, [start, end]) => total + end - start, 0);
const videoStartTime = (stream: Stream) => new Date(new Date(stream.started_at).getTime() + stream.video_offset_seconds * 1000).toLocaleTimeString('nl-NL', { hour: '2-digit', minute: '2-digit' });

let pollTimer: number | undefined;
let downloadTimer: number | undefined;
const pollStatuses = () => { streams.value.filter((stream) => isTranscriptionActive(stream) || isEventExtractionActive(stream)).forEach((stream) => { void refreshStatus(stream); }); };
// The job saves the download progress every few seconds; reload the table until the downloads are done.
const pollDownloads = () => { if (streams.value.some(isDownloadActive)) router.reload({ only: ['streams'] }); };
onMounted(() => { pollStatuses(); pollTimer = window.setInterval(pollStatuses, 1500); downloadTimer = window.setInterval(pollDownloads, 3000); });
onBeforeUnmount(() => { window.clearInterval(pollTimer); window.clearInterval(downloadTimer); });
</script>

<template>
    <div class="streams-panel"><div class="streams-table-wrap"><table class="streams-table"><thead><tr><th>Titel</th><th v-if="showPlayer">Speler</th><th>Datum</th><th>Media</th><th>Transcriptie</th><th><span class="sr-only">Acties</span></th></tr></thead><tbody>
        <tr v-for="stream in streams" :key="stream.id" class="stream-row" @click="openStream($event, stream)">
            <td class="stream-title-cell"><Link class="stream-title" :href="streamUrl(stream)" :title="stream.title">{{ stream.title }}</Link><div class="stream-source">{{ stream.source || 'Geen bron' }}</div></td>
            <td v-if="showPlayer"><Link class="player-cell" :href="'/players/' + stream.player.id"><PlayerAvatar :name="stream.player.name" :photo-url="stream.player.photo_url" size="sm" />{{ stream.player.name }}</Link></td>
            <td class="stream-date-cell">
                <div>{{ dayLabel(stream.started_at) }}</div>
                <div v-if="stream.status === 'Live'" class="stream-time"><span class="status-badge status-live"><span />Live</span> sinds {{ timeLabel(stream.started_at) }}</div>
                <div v-else class="stream-time">{{ timeLabel(stream.started_at) }}<template v-if="stream.ended_at"> – {{ timeLabel(stream.ended_at) }}</template></div>
                <div v-if="streamDurationLabel(stream)" class="stream-length" :title="stream.status === 'Live' ? 'Zo lang is de stream nu bezig' : 'Totale duur van de stream'">{{ streamDurationLabel(stream) }}<template v-if="stream.status === 'Live'"> bezig</template></div>
            </td>
            <td>
                <template v-if="stream.video_path"><span class="video-indicator video-present">{{ stream.video_mime_type?.startsWith('audio/') ? '✓ Audio' : '✓ Video' }}<template v-if="stream.video_file_size !== null"> · {{ formatBytes(stream.video_file_size) }}</template></span><span v-if="stream.transcription_ranges" class="transcription-stage" title="Alleen het deel in de categorie CreatorSMP tussen 14:00 en 00:00 wordt getranscribeerd.">SMP-deel {{ formatSeconds(smpSeconds(stream)) }}</span><span v-else-if="stream.video_offset_seconds > 0" class="transcription-stage">vanaf {{ videoStartTime(stream) }}</span></template>
                <template v-else-if="isDownloadActive(stream)">
                    <span class="transcription-badge transcription-processing">{{ downloadLabel(stream) }}</span>
                    <div v-if="stream.video_download_status === 'processing'" class="progress-track progress-track-small"><span :style="{ width: stream.video_download_progress + '%' }" /></div>
                    <button v-if="canCancel(stream, 'video_download')" class="cancel-link" type="button" @click="cancelTask(stream, 'video_download')">✕ Annuleren</button>
                    <span v-else-if="isCancelling(stream, 'video_download')" class="transcription-stage">Wordt geannuleerd…</span>
                </template>
                <span v-else-if="stream.twitch_video_id && !stream.ended_at" class="transcription-stage" title="Sync de VOD's opnieuw als de stream voorbij is.">Nog live · audio na afloop</span>
                <button v-else-if="stream.twitch_video_id" class="secondary-button" type="button" @click="downloadAudio(stream)">Audio ophalen</button>
                <span v-else class="video-indicator video-missing">— Geen video</span>
                <p v-if="stream.video_download_stalled" class="stream-error">{{ stalledMessage }}</p>
                <p v-if="stream.video_download_error && !stream.video_path" class="stream-error">{{ stream.video_download_error }}</p>
                <div v-if="stream.twitch_url"><a class="twitch-link" :href="stream.twitch_url" target="_blank" rel="noopener noreferrer">{{ stream.ended_at ? 'Bekijk op Twitch ↗' : 'Kijk live op Twitch ↗' }}</a></div>
            </td>
            <td><div class="transcription-details">
                <span class="transcription-badge" :class="transcriptionBadgeClass(stream)">{{ transcriptionLabel(stream.transcription_status) }}</span>
                <span v-if="stream.transcription_status !== 'pending'" class="transcription-stage">{{ stageLabel(stream.transcription_stage) }}</span>
                <div v-if="isTranscriptionActive(stream)" class="progress-track"><span :style="{ width: Math.max(0, Math.min(100, stream.transcription_progress)) + '%' }" /></div>
                <div v-if="isTranscriptionActive(stream) || stream.transcription_status === 'completed'" class="transcription-meta"><span>{{ Math.max(0, Math.min(100, stream.transcription_progress)) }}%</span><span>{{ formatSeconds(stream.transcription_processed_seconds) }} / {{ formatSeconds(stream.transcription_duration_seconds) }}</span><span v-if="displayEta(stream) !== null && stream.transcription_status === 'processing'">nog ~ {{ formatSeconds(displayEta(stream)) }}</span><span>{{ stream.transcription_segment_count }} segmenten</span></div>
                <span v-if="stream.worker_name" class="transcription-stage">Op worker {{ stream.worker_name }}</span>
                <button v-if="canCancel(stream, 'transcription')" class="cancel-link" type="button" @click="cancelTask(stream, 'transcription')">✕ Annuleren</button>
                <span v-else-if="isCancelling(stream, 'transcription')" class="transcription-stage">Wordt geannuleerd…</span>
                <p v-if="stream.transcription_stalled" class="stream-error">{{ stalledMessage }}</p>
                <p v-if="stream.transcription_error" class="stream-error">{{ stream.transcription_error }}</p>
                <span v-if="stream.event_extraction_status !== 'pending'" class="event-extraction-status"><span class="transcription-stage">Events</span><span class="transcription-badge" :class="eventBadgeClass(stream)">{{ eventExtractionLabel(stream.event_extraction_status) }}</span></span>
                <template v-if="isEventExtractionActive(stream)">
                    <div class="progress-track" :class="{ 'progress-indeterminate': stream.event_extraction_progress === null }"><span :style="stream.event_extraction_progress === null ? {} : { width: stream.event_extraction_progress + '%' }" /></div>
                    <div v-if="stream.event_extraction_progress !== null" class="transcription-meta"><span>{{ stream.event_extraction_progress }}%</span><span>{{ eventProgressDetails(stream) }}</span></div>
                </template>
                <button v-if="canCancel(stream, 'event_extraction')" class="cancel-link" type="button" @click="cancelTask(stream, 'event_extraction')">✕ Annuleren</button>
                <span v-else-if="isCancelling(stream, 'event_extraction')" class="transcription-stage">Wordt geannuleerd…</span>
                <p v-if="stream.event_extraction_stalled" class="stream-error">{{ stalledMessage }}</p>
                <p v-if="stream.event_extraction_error" class="stream-error">{{ stream.event_extraction_error }}</p>
            </div></td>
            <td class="action-cell">
                <span v-if="isTranscriptionActive(stream)" class="stream-hint">Transcriptie bezig…</span>
                <button v-if="stream.transcription_status !== 'completed'" class="secondary-button" type="button" :disabled="!stream.video_path || isTranscriptionActive(stream)" @click="transcribe(stream)">{{ transcribeButtonLabel(stream) }}</button>
                <button v-else class="secondary-button" type="button" :disabled="!stream.has_transcript || isEventExtractionActive(stream)" @click="extractEvents(stream)">{{ extractButtonLabel(stream) }}</button>
                <button v-if="stream.transcription_status === 'completed'" class="icon-button" type="button" title="Opnieuw transcriberen" aria-label="Opnieuw transcriberen" :disabled="!stream.video_path || isTranscriptionActive(stream) || isEventExtractionActive(stream)" @click="retranscribe(stream)"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M16 10a6 6 0 1 1-1.8-4.3" /><path d="M16 3.5v3.7h-3.7" /></svg></button>
                <button class="icon-button icon-button-danger" type="button" title="Stream verwijderen" aria-label="Stream verwijderen" @click="removeStream(stream)"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M4 6h12M8 6V4h4v2M6 6l.7 10h6.6L14 6M8.5 9v4.5M11.5 9v4.5" /></svg></button>
            </td>
        </tr>
    </tbody></table></div></div>
</template>
