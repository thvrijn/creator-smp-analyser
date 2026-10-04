<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { eventBadgeClass, eventExtractionLabel, extractButtonLabel, extractEvents, formatSeconds, isEventExtractionActive, isTranscriptionActive, refreshStatus, stageLabel, transcribe, transcribeButtonLabel, transcriptionLabel } from '../composables/streamStatus';
import { formatDate, type Stream } from '../types/streams';

const props = withDefaults(defineProps<{ streams: Stream[]; showPlayer?: boolean }>(), { showPlayer: true });
const streams = ref<Stream[]>(props.streams);
watch(() => props.streams, (value) => { streams.value = value; }, { deep: true });

const streamUrl = (stream: Stream) => '/streams/' + stream.id;
const removeStream = (stream: Stream) => { if (window.confirm('Delete "' + stream.title + '"?')) router.delete(streamUrl(stream), { preserveScroll: true }); };
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

let pollTimer: number | undefined;
const pollStatuses = () => { streams.value.filter((stream) => isTranscriptionActive(stream) || isEventExtractionActive(stream)).forEach((stream) => { void refreshStatus(stream); }); };
onMounted(() => { pollStatuses(); pollTimer = window.setInterval(pollStatuses, 1500); });
onBeforeUnmount(() => { if (pollTimer !== undefined) window.clearInterval(pollTimer); });
</script>

<template>
    <div class="streams-panel"><div class="streams-table-wrap"><table class="streams-table"><thead><tr><th>Title</th><th v-if="showPlayer">Player</th><th>Started</th><th>Ended</th><th>Status</th><th>Video</th><th>Transcription</th><th><span class="sr-only">Actions</span></th></tr></thead><tbody>
        <tr v-for="stream in streams" :key="stream.id" class="stream-row" @click="openStream($event, stream)">
            <td><Link class="stream-title" :href="streamUrl(stream)">{{ stream.title }}</Link><div class="stream-source">{{ stream.source || 'No source' }}</div></td>
            <td v-if="showPlayer"><Link class="player-cell" :href="'/players/' + stream.player.id"><span class="player-avatar">{{ stream.player.name.charAt(0) }}</span>{{ stream.player.name }}</Link></td>
            <td>{{ formatDate(stream.started_at) }}</td>
            <td>{{ formatDate(stream.ended_at) }}</td>
            <td><span class="status-badge" :class="stream.status === 'Live' ? 'status-live' : 'status-finished'"><span />{{ stream.status }}</span></td>
            <td><span class="video-indicator" :class="stream.video_path ? 'video-present' : 'video-missing'">{{ stream.video_path ? '✓ Present' : '— No video' }}</span></td>
            <td><div class="transcription-details">
                <span class="transcription-badge" :class="'transcription-' + stream.transcription_status">{{ transcriptionLabel(stream.transcription_status) }}</span>
                <span v-if="stream.transcription_status !== 'pending'" class="transcription-stage">{{ stageLabel(stream.transcription_stage) }}</span>
                <div v-if="isTranscriptionActive(stream)" class="progress-track"><span :style="{ width: Math.max(0, Math.min(100, stream.transcription_progress)) + '%' }" /></div>
                <div v-if="isTranscriptionActive(stream) || stream.transcription_status === 'completed'" class="transcription-meta"><span>{{ Math.max(0, Math.min(100, stream.transcription_progress)) }}%</span><span>{{ formatSeconds(stream.transcription_processed_seconds) }} / {{ formatSeconds(stream.transcription_duration_seconds) }}</span><span v-if="displayEta(stream) !== null && stream.transcription_status === 'processing'">~ {{ formatSeconds(displayEta(stream)) }} left</span><span>{{ stream.transcription_segment_count }} segments</span></div>
                <p v-if="stream.transcription_error" class="stream-error">{{ stream.transcription_error }}</p>
                <span v-if="stream.event_extraction_status !== 'pending'" class="event-extraction-status"><span class="transcription-stage">Events</span><span class="transcription-badge" :class="eventBadgeClass(stream)">{{ eventExtractionLabel(stream.event_extraction_status) }}</span></span>
                <p v-if="stream.event_extraction_error" class="stream-error">{{ stream.event_extraction_error }}</p>
            </div></td>
            <td class="action-cell">
                <span v-if="isTranscriptionActive(stream)" class="stream-hint">Transcript in progress…</span>
                <button v-if="stream.transcription_status !== 'completed'" class="secondary-button" type="button" :disabled="!stream.video_path || isTranscriptionActive(stream)" @click="transcribe(stream)">{{ transcribeButtonLabel(stream) }}</button>
                <button v-else class="secondary-button" type="button" :disabled="!stream.has_transcript || isEventExtractionActive(stream)" @click="extractEvents(stream)">{{ extractButtonLabel(stream) }}</button>
                <button class="delete-button" type="button" @click="removeStream(stream)">Delete</button>
            </td>
        </tr>
    </tbody></table></div></div>
</template>
