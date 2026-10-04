<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import FlashMessages from '../../Components/FlashMessages.vue';
import { eventBadgeClass, eventExtractionLabel, extractButtonLabel, extractEvents, formatSeconds, isEventExtractionActive, isTranscriptionActive, refreshStatus, stageLabel, transcribe, transcribeButtonLabel, transcriptionLabel } from '../../composables/streamStatus';
import AppLayout from '../../Layouts/AppLayout.vue';
import { formatDate, type Stream } from '../../types/streams';

type StreamDetail = Stream & { duration_seconds: number | null; segment_count: number };
type StreamEvent = { id: number; type: string; title: string; description: string; start_time: number; end_time: number; confidence: number; segment_count: number };
type Segment = { id: number; start_time: number; end_time: number; text: string };
type PaginationLink = { url: string | null; label: string; active: boolean };
type Pagination = { data: Segment[]; current_page: number; last_page: number; per_page: number; total: number; from: number | null; to: number | null };

const props = defineProps<{ stream: StreamDetail; events: StreamEvent[]; selected_event_id: number | null; highlighted_segment_ids: number[]; segments: Pagination; pagination: PaginationLink[]; search: string }>();
const stream = reactive<StreamDetail>({ ...props.stream });
watch(() => props.stream, (value) => { Object.assign(stream, value); }, { deep: true });
const search = ref(props.search);
watch(() => props.search, (value) => { search.value = value; });

const streamUrl = '/streams/' + props.stream.id;
const eventTypeLabel = (type: string) => type.replace(/_/g, ' ');
const isHighlighted = (segment: Segment) => props.highlighted_segment_ids.includes(segment.id);
const submitSearch = () => { router.get(streamUrl, search.value.trim() ? { search: search.value.trim() } : {}, { preserveState: true, preserveScroll: true, replace: true }); };
const selectEvent = (event: StreamEvent) => {
    const deselect = props.selected_event_id === event.id;
    router.get(streamUrl, deselect ? {} : { event: event.id }, { preserveState: true, preserveScroll: true, replace: true, only: ['selected_event_id', 'highlighted_segment_ids', 'segments', 'pagination', 'search'] });
};

// Bring the first highlighted segment into view inside the transcript list.
const transcriptList = ref<HTMLElement | null>(null);
const scrollToHighlight = async () => {
    await nextTick();
    transcriptList.value?.querySelector('.segment-highlight')?.scrollIntoView({ block: 'center', behavior: 'smooth' });
};
watch(() => props.highlighted_segment_ids, () => { void scrollToHighlight(); });

// While a job runs, poll the status and reload the page data once it finishes.
let pollTimer: number | undefined;
const poll = async () => {
    if (!isTranscriptionActive(stream) && !isEventExtractionActive(stream)) return;
    await refreshStatus(stream);
    if (!isTranscriptionActive(stream) && !isEventExtractionActive(stream)) router.reload();
};
onMounted(() => { void scrollToHighlight(); pollTimer = window.setInterval(() => { void poll(); }, 2000); });
onBeforeUnmount(() => { if (pollTimer !== undefined) window.clearInterval(pollTimer); });
</script>

<template>
    <Head :title="stream.title" />
    <AppLayout :title="stream.title" eyebrow="Stream">
        <Link class="back-link" :href="'/players/' + stream.player.id">← {{ stream.player.name }}</Link>
        <section class="stream-hero">
            <div class="stream-hero-text">
                <p class="section-kicker">Stream</p>
                <h2 class="page-section-title">{{ stream.title }}</h2>
                <p class="muted-copy"><Link class="inline-link" :href="'/players/' + stream.player.id">{{ stream.player.name }}</Link> · {{ formatDate(stream.started_at) }} · {{ formatSeconds(stream.duration_seconds) }} · {{ stream.segment_count }} segments · {{ events.length }} events</p>
                <div class="stream-hero-statuses">
                    <span class="status-pair"><span class="transcription-stage">Transcript</span><span class="transcription-badge" :class="'transcription-' + stream.transcription_status">{{ transcriptionLabel(stream.transcription_status) }}</span><span v-if="isTranscriptionActive(stream)" class="transcription-stage">{{ stageLabel(stream.transcription_stage) }} · {{ Math.round(stream.transcription_progress) }}%</span></span>
                    <span class="status-pair"><span class="transcription-stage">Events</span><span class="transcription-badge" :class="eventBadgeClass(stream)">{{ eventExtractionLabel(stream.event_extraction_status) }}</span></span>
                </div>
                <p v-if="stream.transcription_error" class="stream-error">{{ stream.transcription_error }}</p>
                <p v-if="stream.event_extraction_error" class="stream-error">{{ stream.event_extraction_error }}</p>
            </div>
            <button v-if="stream.transcription_status !== 'completed'" class="primary-button" type="button" :disabled="!stream.video_path || isTranscriptionActive(stream)" @click="transcribe(stream)">{{ transcribeButtonLabel(stream) }}</button>
            <button v-else class="primary-button" type="button" :disabled="!stream.has_transcript || isEventExtractionActive(stream)" @click="extractEvents(stream)">{{ extractButtonLabel(stream) }}</button>
        </section>
        <FlashMessages />

        <div class="stream-detail-grid">
            <section class="detail-panel" aria-labelledby="events-heading">
                <div class="panel-heading"><div><p class="section-kicker">Analysis</p><h3 id="events-heading">Events</h3></div><span class="panel-badge">{{ events.length }}</span></div>
                <div v-if="events.length" class="event-list">
                    <button v-for="event in events" :key="event.id" type="button" class="event-item" :class="{ 'event-item-active': event.id === selected_event_id }" :aria-pressed="event.id === selected_event_id" @click="selectEvent(event)">
                        <span class="event-item-top"><span class="event-type" :class="'event-type-' + event.type">{{ eventTypeLabel(event.type) }}</span><span class="event-time">{{ formatSeconds(event.start_time) }} – {{ formatSeconds(event.end_time) }}</span></span>
                        <span class="event-title">{{ event.title }}</span>
                        <span class="event-description">{{ event.description }}</span>
                        <span class="event-meta">{{ Math.round(event.confidence * 100) }}% confidence · {{ event.segment_count }} segments</span>
                    </button>
                </div>
                <div v-else class="detail-empty">
                    <h3>No events yet</h3>
                    <p>{{ stream.transcription_status === 'completed' ? 'Run “Extract events” to find events in this transcript.' : 'Transcribe the stream first, then extract events.' }}</p>
                </div>
            </section>

            <section class="detail-panel" aria-labelledby="transcript-heading">
                <div class="panel-heading"><div><p class="section-kicker">Transcript</p><h3 id="transcript-heading">Transcript</h3></div><span v-if="selected_event_id" class="panel-badge">Event selected · <button class="link-button" type="button" @click="router.get(streamUrl, {}, { preserveScroll: true, replace: true })">clear</button></span></div>
                <form class="transcript-search" @submit.prevent="submitSearch">
                    <label class="sr-only" for="transcript-search">Search transcript</label>
                    <input id="transcript-search" v-model="search" type="search" placeholder="Search transcript…" />
                    <button class="secondary-button" type="submit">Search</button>
                </form>
                <div v-if="segments.data.length" ref="transcriptList" class="transcript-list transcript-list-scroll">
                    <article v-for="segment in segments.data" :key="segment.id" class="transcript-segment" :class="{ 'segment-highlight': isHighlighted(segment) }">
                        <div class="segment-time">{{ formatSeconds(segment.start_time) }}</div>
                        <div class="segment-copy"><p>{{ segment.text }}</p><span>{{ formatSeconds(segment.end_time) }}</span></div>
                    </article>
                </div>
                <div v-else class="detail-empty">
                    <h3>{{ search ? 'No transcript segments found' : 'No transcript available yet' }}</h3>
                    <p>{{ search ? `No results for “${search}”. Try another search term.` : 'This stream does not have any transcript segments.' }}</p>
                </div>
                <div v-if="segments.total > 0" class="transcript-pagination">
                    <span class="pagination-summary">{{ segments.from }}–{{ segments.to }} of {{ segments.total }}</span>
                    <nav aria-label="Transcript pagination">
                        <Link v-for="link in pagination" :key="link.label + (link.url || '')" class="pagination-link" :class="{ 'pagination-active': link.active, 'pagination-disabled': !link.url }" :href="link.url || '#'" preserve-scroll><span v-html="link.label" /></Link>
                    </nav>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
