<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import FlashMessages from '../../Components/FlashMessages.vue';
import AudioPlayer from '../../Components/AudioPlayer.vue';
import TwitchPlayer from '../../Components/TwitchPlayer.vue';
import { downloadAudio, formatBytes, downloadLabel, eventBadgeClass, eventProgressDetails, isDownloadActive, retranscribe, eventExtractionLabel, eventTypeLabel, extractButtonLabel, extractEvents, formatSeconds, isEventExtractionActive, isTranscriptionActive, refreshStatus, stageLabel, stalledMessage, transcribe, transcribeButtonLabel, transcriptionBadgeClass, transcriptionLabel } from '../../composables/streamStatus';
import AppLayout from '../../Layouts/AppLayout.vue';
import { formatDate, type Stream } from '../../types/streams';

type StreamDetail = Stream & { duration_seconds: number | null; segment_count: number };
type StreamEvent = { id: number; type: string; title: string; description: string; start_time: number; end_time: number; confidence: number; segment_count: number };
type Segment = { id: number; start_time: number; end_time: number; text: string; speaker: number | null };
type PaginationLink = { url: string | null; label: string; active: boolean };
type Clip = { id: number; event_id: number | null; title: string; start_seconds: number; end_seconds: number };
type Pagination = { data: Segment[]; current_page: number; last_page: number; per_page: number; total: number; from: number | null; to: number | null };

const props = defineProps<{ stream: StreamDetail; events: StreamEvent[]; clips: Clip[]; selected_event_id: number | null; highlighted_segment_ids: number[]; segments: Pagination; pagination: PaginationLink[]; search: string }>();
const stream = reactive<StreamDetail>({ ...props.stream });
watch(() => props.stream, (value) => { Object.assign(stream, value); }, { deep: true });
const search = ref(props.search);
watch(() => props.search, (value) => { search.value = value; });

const streamUrl = '/streams/' + props.stream.id;
const isHighlighted = (segment: Segment) => props.highlighted_segment_ids.includes(segment.id);
// Speaker 0 speaks the most in the stream: almost always the streamer. The others are numbered voices, not yet names.
const speakerLabel = (speaker: number) => speaker === 0 ? stream.player.name : `Spreker ${speaker}`;
const selectedEvent = computed(() => props.events.find((event) => event.id === props.selected_event_id));

// The tab lives in the URL (?tab=), so links and the back button work. Without one, a selected
// event, search or page means the transcript is what you came for.
type Tab = 'events' | 'transcript' | 'clips';
const tabs: Tab[] = ['events', 'transcript', 'clips'];
const tabLabels: Record<Tab, string> = { events: 'Events', transcript: 'Transcript', clips: 'Clips' };
const page = usePage();
const query = computed(() => Object.fromEntries(new URL(page.url, window.location.origin).searchParams));
const activeTab = computed<Tab>(() => tabs.includes(query.value.tab as Tab) ? query.value.tab as Tab : (query.value.clip ? 'clips' : query.value.event || query.value.search || query.value.page ? 'transcript' : 'events'));
const tabCount = (tab: Tab) => ({ events: props.events.length, transcript: stream.segment_count, clips: clips.value.length })[tab];
const tabUrl = (tab: Tab) => streamUrl + '?' + new URLSearchParams({ ...query.value, tab });

const submitSearch = () => { router.get(streamUrl, { tab: 'transcript', ...(search.value.trim() ? { search: search.value.trim() } : {}) }, { preserveState: true, preserveScroll: true, replace: true }); };
const clearSelection = () => { router.get(streamUrl, { tab: 'transcript' }, { preserveState: true, preserveScroll: true, replace: true }); };
// Selecting an event opens it in the transcript; clicking the selected event again deselects it.
const selectEvent = (event: StreamEvent) => {
    const deselect = props.selected_event_id === event.id;
    router.get(streamUrl, deselect ? {} : { event: event.id }, { preserveState: true, preserveScroll: true, only: ['selected_event_id', 'highlighted_segment_ids', 'segments', 'pagination', 'search'] });
};

// Bring the first highlighted segment into view inside the transcript list.
const transcriptList = ref<HTMLElement | null>(null);
const scrollToHighlight = async () => {
    await nextTick();
    const list = transcriptList.value;
    const segment = list?.querySelector<HTMLElement>('.segment-highlight');
    if (!list || !segment) return;
    // Scroll only the list, never the page around it (on small screens the page scrolls instead).
    if (list.scrollHeight > list.clientHeight) list.scrollTop = segment.offsetTop - (list.clientHeight - segment.clientHeight) / 2;
    else segment.scrollIntoView({ block: 'center' });
};
watch([() => props.highlighted_segment_ids, activeTab], () => { void scrollToHighlight(); });

// The stream's own audio file. Segment and event times are times in that file, so they seek straight to the moment.
const audio = ref<InstanceType<typeof AudioPlayer> | null>(null);
const audioTime = ref<number | null>(null);
const audioPlaying = ref(false);
const playFrom = (seconds: number) => { void audio.value?.playFrom(seconds); };
// The segment on this transcript page that is playing now; it lights up and the list follows it while playing.
const playingSegmentId = computed(() => {
    const time = audioTime.value;
    if (time === null) return null;
    return props.segments.data.find((segment) => segment.start_time <= time && time < segment.end_time)?.id ?? null;
});
watch(playingSegmentId, async (id) => {
    if (id === null || !audioPlaying.value) return;
    await nextTick();
    const list = transcriptList.value;
    const segment = list?.querySelector<HTMLElement>('.segment-playing');
    if (!list || !segment || list.scrollHeight <= list.clientHeight) return;
    list.scrollTop = segment.offsetTop - (list.clientHeight - segment.clientHeight) / 2;
});

// Clips are in seconds from the stream (VOD) start; transcript and event times are from the start of the media file,
// which for a Twitch VOD can start later (video_offset_seconds).
const clips = ref<Clip[]>(props.clips.map((clip) => ({ ...clip })));
watch(() => props.clips, (value) => { clips.value = value.map((clip) => ({ ...clip })); });
const offset = computed(() => stream.video_offset_seconds ?? 0);
const streamLength = computed(() => stream.twitch_video_id && stream.ended_at
    ? (Date.parse(stream.ended_at) - Date.parse(stream.started_at)) / 1000
    : offset.value + (stream.duration_seconds ?? 0));
const selectedClipId = ref<number | null>(Number(query.value.clip) || null);
watch(() => query.value.clip, (value) => { if (value) selectedClipId.value = Number(value); });
const selectedClip = computed(() => clips.value.find((clip) => clip.id === selectedClipId.value) ?? clips.value[0] ?? null);
const eventClip = (event: StreamEvent) => clips.value.find((clip) => clip.event_id === event.id);
const clipUrl = (clip: Clip) => streamUrl + '?' + new URLSearchParams({ tab: 'clips', clip: String(clip.id) });
const clockTime = (seconds: number) => new Date(Date.parse(stream.started_at) + seconds * 1000).toLocaleTimeString('nl-NL', { hour: '2-digit', minute: '2-digit', second: '2-digit' });

const createClip = (clip: Omit<Clip, 'id' | 'event_id'> & { event_id?: number }) => router.post(streamUrl + '/clips', clip, { preserveScroll: true });
const clipFromEvent = (event: StreamEvent) => createClip({
    event_id: event.id, title: event.title,
    start_seconds: Math.max(0, offset.value + event.start_time - 15), end_seconds: Math.min(streamLength.value, offset.value + event.end_time + 15),
});
const clipFromSegment = (segment: Segment) => createClip({
    title: segment.text.slice(0, 80),
    start_seconds: Math.max(0, offset.value + segment.start_time - 5), end_seconds: Math.min(streamLength.value, offset.value + segment.start_time + 55),
});
const clipWholeStream = () => createClip({ title: 'Hele stream', start_seconds: 0, end_seconds: streamLength.value });

// Nudging saves after a short pause, so a row of clicks is one request.
const saveTimers = new Map<number, number>();
const saveClip = (clip: Clip) => {
    window.clearTimeout(saveTimers.get(clip.id));
    saveTimers.set(clip.id, window.setTimeout(() => router.put('/clips/' + clip.id, { title: clip.title, start_seconds: clip.start_seconds, end_seconds: clip.end_seconds }, { preserveScroll: true, preserveState: true }), 400));
};
const nudge = (clip: Clip, edge: 'start_seconds' | 'end_seconds', delta: number) => {
    if (edge === 'start_seconds') clip.start_seconds = Math.max(0, Math.min(clip.start_seconds + delta, clip.end_seconds - 1));
    else clip.end_seconds = Math.max(clip.start_seconds + 1, Math.min(clip.end_seconds + delta, streamLength.value || Infinity));
    selectedClipId.value = clip.id;
    saveClip(clip);
};
const removeClip = (clip: Clip) => { if (window.confirm('"' + clip.title + '" verwijderen?')) router.delete('/clips/' + clip.id, { preserveScroll: true }); };
const player = ref<InstanceType<typeof TwitchPlayer> | null>(null);
const watchClip = async (clip: Clip) => { selectedClipId.value = clip.id; await nextTick(); player.value?.play(); };

// While a job runs, poll the status and reload the page data once it finishes.
let pollTimer: number | undefined;
// The audio download's progress is only in the page data, so reload the stream while it runs.
const audioState = computed(() => stream.video_path ? { badge: 'completed', label: 'Klaar' }
    : isDownloadActive(stream) ? { badge: 'processing', label: downloadLabel(stream) }
    : stream.video_download_status === 'failed' ? { badge: 'failed', label: 'Mislukt' }
    : !stream.ended_at ? { badge: 'pending', label: 'Nog live' }
    : { badge: 'pending', label: 'Niet opgehaald' });
const poll = async () => {
    if (isDownloadActive(stream)) { router.reload({ only: ['stream'] }); return; }
    if (!isTranscriptionActive(stream) && !isEventExtractionActive(stream)) return;
    await refreshStatus(stream);
    if (!isTranscriptionActive(stream) && !isEventExtractionActive(stream)) router.reload();
};
onMounted(() => { void scrollToHighlight(); pollTimer = window.setInterval(() => { void poll(); }, 2000); });
onBeforeUnmount(() => { if (pollTimer !== undefined) window.clearInterval(pollTimer); });
</script>

<template>
    <Head :title="stream.title" />
    <AppLayout :title="stream.title" eyebrow="Stream" fill>
        <Link class="back-link" :href="'/players/' + stream.player.id">← {{ stream.player.name }}</Link>
        <section class="stream-hero">
            <div class="stream-hero-text">
                <p class="section-kicker">Stream</p>
                <h2 class="page-section-title">{{ stream.title }}</h2>
                <p class="muted-copy"><Link class="inline-link" :href="'/players/' + stream.player.id">{{ stream.player.name }}</Link> · {{ formatDate(stream.started_at) }} · {{ formatSeconds(stream.duration_seconds) }} · {{ stream.segment_count }} segmenten · {{ events.length }} events</p>
                <div class="stream-hero-statuses">
                    <span v-if="stream.twitch_video_id" class="status-pair"><span class="transcription-stage">Audio</span><span class="transcription-badge" :class="'transcription-' + audioState.badge">{{ audioState.label }}</span></span>
                    <span class="status-pair"><span class="transcription-stage">Transcript</span><span class="transcription-badge" :class="transcriptionBadgeClass(stream)">{{ transcriptionLabel(stream.transcription_status) }}</span><span v-if="isTranscriptionActive(stream)" class="transcription-stage">{{ stageLabel(stream.transcription_stage) }} · {{ Math.round(stream.transcription_progress) }}%</span></span>
                    <span class="status-pair"><span class="transcription-stage">Events</span><span class="transcription-badge" :class="eventBadgeClass(stream)">{{ eventExtractionLabel(stream.event_extraction_status) }}</span><span v-if="isEventExtractionActive(stream) && stream.event_extraction_progress !== null" class="transcription-stage">{{ stream.event_extraction_progress }}% · {{ eventProgressDetails(stream) }}</span></span>
                </div>
                <div v-if="!stream.video_path && stream.video_download_status === 'processing' && !stream.video_download_stalled" class="progress-track"><span :style="{ width: stream.video_download_progress + '%' }" /></div>
                <div v-if="isEventExtractionActive(stream)" class="progress-track" :class="{ 'progress-indeterminate': stream.event_extraction_progress === null }"><span :style="stream.event_extraction_progress === null ? {} : { width: stream.event_extraction_progress + '%' }" /></div>
                <p v-if="stream.worker_name" class="transcription-stage">Draait op worker {{ stream.worker_name }}</p>
                <p v-if="stream.transcription_stalled || stream.event_extraction_stalled || stream.video_download_stalled" class="stream-error">{{ stalledMessage }}</p>
                <p v-if="stream.video_download_error && !stream.video_path" class="stream-error">{{ stream.video_download_error }}</p>
                <p v-if="stream.transcription_error" class="stream-error">{{ stream.transcription_error }}</p>
                <p v-if="stream.event_extraction_error" class="stream-error">{{ stream.event_extraction_error }}</p>
            </div>
            <button v-if="!stream.video_path && stream.twitch_video_id" class="primary-button" type="button" :disabled="isDownloadActive(stream) || !stream.ended_at" :title="stream.ended_at ? undefined : 'Sync de VOD\'s opnieuw als de stream voorbij is.'" @click="downloadAudio(stream)">{{ isDownloadActive(stream) ? 'Audio ophalen…' : 'Audio ophalen' }}</button>
            <button v-else-if="stream.transcription_status !== 'completed'" class="primary-button" type="button" :disabled="!stream.video_path || isTranscriptionActive(stream)" @click="transcribe(stream)">{{ transcribeButtonLabel(stream) }}</button>
            <div v-else class="stream-hero-actions">
                <button class="secondary-button" type="button" :disabled="!stream.video_path || isEventExtractionActive(stream)" @click="retranscribe(stream)">Opnieuw transcriberen</button>
                <button class="primary-button" type="button" :disabled="!stream.has_transcript || isEventExtractionActive(stream)" @click="extractEvents(stream)">{{ extractButtonLabel(stream) }}</button>
            </div>
        </section>
        <FlashMessages />

        <div v-if="stream.video_path" class="stream-audio">
            <span class="transcription-stage">{{ stream.video_mime_type?.startsWith('audio/') ? 'Audio' : 'Video' }}<template v-if="stream.video_file_size !== null"> · {{ formatBytes(stream.video_file_size) }}</template></span>
            <AudioPlayer ref="audio" :src="streamUrl + '/audio'" @time="audioTime = $event" @playing="audioPlaying = $event" />
        </div>

        <nav class="stream-tabs" aria-label="Onderdelen van de stream">
            <Link v-for="tab in tabs" :key="tab" class="stream-tab" :class="{ 'stream-tab-active': activeTab === tab }" :href="tabUrl(tab)" :aria-current="activeTab === tab ? 'page' : undefined" preserve-state preserve-scroll>
                {{ tabLabels[tab] }}<span class="stream-tab-count">{{ tabCount(tab) }}</span>
            </Link>
        </nav>

        <section v-if="activeTab === 'events'" class="detail-panel" aria-label="Events">
            <div v-if="events.length" class="event-list">
                <div v-for="event in events" :key="event.id" class="event-entry">
                    <button type="button" class="event-item" :class="{ 'event-item-active': event.id === selected_event_id }" :aria-pressed="event.id === selected_event_id" @click="selectEvent(event)">
                        <span class="event-item-top"><span v-if="event.type !== 'other'" class="event-type" :class="'event-type-' + event.type">{{ eventTypeLabel(event.type) }}</span><span class="event-time event-time-end">{{ formatSeconds(event.start_time) }} – {{ formatSeconds(event.end_time) }}</span></span>
                        <span class="event-title">{{ event.title }}</span>
                        <span class="event-description">{{ event.description }}</span>
                        <span class="event-meta">{{ Math.round(event.confidence * 100) }}% zekerheid · {{ event.segment_count }} {{ event.segment_count === 1 ? 'segment' : 'segmenten' }}</span>
                    </button>
                    <span class="event-actions">
                        <button v-if="stream.video_path" class="event-clip-button" type="button" title="Afspelen vanaf dit event" @click="playFrom(event.start_time)">▶ Afspelen</button>
                        <Link v-if="eventClip(event)" class="event-clip-button event-clip-done" :href="clipUrl(eventClip(event)!)" preserve-state preserve-scroll>✓ Clip</Link>
                        <button v-else class="event-clip-button" type="button" @click="clipFromEvent(event)">＋ Clip</button>
                    </span>
                </div>
            </div>
            <div v-else class="detail-empty">
                <h3>Nog geen events</h3>
                <p>{{ stream.transcription_status === 'completed' ? 'Klik op “Analyseren” om events in dit transcript te vinden.' : 'Transcribeer eerst de stream en analyseer hem daarna.' }}</p>
            </div>
        </section>

        <section v-else-if="activeTab === 'clips'" class="detail-panel clips-panel" aria-label="Clips">
            <div class="clip-stage">
                <div v-if="stream.twitch_video_id && selectedClip" class="clip-player"><TwitchPlayer ref="player" :video-id="stream.twitch_video_id" :start="selectedClip.start_seconds" :end="selectedClip.end_seconds" /></div>
                <p v-else class="muted-copy">{{ stream.twitch_video_id ? 'Maak of kies een clip om hem hier te bekijken.' : 'Bekijken kan alleen bij een Twitch-VOD. Clips maken en bijstellen kan wel.' }}</p>
            </div>
            <div class="clip-side">
            <div class="panel-heading"><p class="muted-copy">Maak clips met “＋ Clip” bij een event of “✂ Clip” in het transcript.</p><button class="secondary-button" type="button" :disabled="!streamLength" @click="clipWholeStream">Hele stream</button></div>
            <div v-if="clips.length" class="clip-list">
                <article v-for="clip in clips" :key="clip.id" class="clip-item" :class="{ 'clip-item-active': clip.id === selectedClip?.id }" @click="selectedClipId = clip.id">
                    <input v-model="clip.title" class="clip-title" type="text" aria-label="Titel van de clip" @change="saveClip(clip)" />
                    <span class="clip-time">{{ clockTime(clip.start_seconds) }} – {{ clockTime(clip.end_seconds) }} · {{ formatSeconds(clip.end_seconds - clip.start_seconds) }}</span>
                    <span class="clip-controls">
                        <span class="clip-edge">Begin <button type="button" @click.stop="nudge(clip, 'start_seconds', -5)">−5s</button><button type="button" @click.stop="nudge(clip, 'start_seconds', 5)">+5s</button></span>
                        <span class="clip-edge">Einde <button type="button" @click.stop="nudge(clip, 'end_seconds', -5)">−5s</button><button type="button" @click.stop="nudge(clip, 'end_seconds', 5)">+5s</button></span>
                        <button v-if="stream.twitch_video_id" class="secondary-button" type="button" @click.stop="watchClip(clip)">▶ Bekijk</button>
                        <button class="delete-button" type="button" @click.stop="removeClip(clip)">Verwijderen</button>
                    </span>
                </article>
            </div>
            <div v-else class="detail-empty"><h3>Nog geen clips</h3><p>Kies momenten voor je video: klik op “＋ Clip” bij een event, op “✂ Clip” in het transcript, of neem de hele stream.</p></div>
            </div>
        </section>

        <section v-else class="detail-panel" aria-label="Transcript">
            <div v-if="selectedEvent" class="panel-heading"><p class="muted-copy">Gemarkeerd: <strong>{{ selectedEvent.title }}</strong></p><button class="secondary-button" type="button" @click="clearSelection">Wissen</button></div>
            <form class="transcript-search" @submit.prevent="submitSearch">
                <label class="sr-only" for="transcript-search">Transcript doorzoeken</label>
                <input id="transcript-search" v-model="search" type="search" placeholder="Zoek in transcript…" />
                <button class="secondary-button" type="submit">Zoeken</button>
            </form>
            <div v-if="segments.data.length" ref="transcriptList" class="transcript-list transcript-list-scroll">
                <article v-for="segment in segments.data" :key="segment.id" class="transcript-segment" :class="{ 'segment-highlight': isHighlighted(segment), 'segment-playing': segment.id === playingSegmentId }">
                    <div class="segment-time">{{ formatSeconds(segment.start_time) }}</div>
                    <div class="segment-copy"><div v-if="segment.speaker !== null" class="segment-speaker" :class="'segment-speaker-' + (segment.speaker === 0 ? 'main' : segment.speaker % 6)" :title="segment.speaker === 0 ? 'Spreekt het meest in deze stream, waarschijnlijk de streamer' : 'Een andere stem in deze stream'">{{ speakerLabel(segment.speaker) }}</div><p>{{ segment.text }}</p><span>{{ formatSeconds(segment.end_time) }}</span></div>
                    <div class="segment-actions">
                        <button v-if="stream.video_path" class="segment-clip" type="button" title="Afspelen vanaf hier" @click="playFrom(segment.start_time)">▶</button>
                        <button class="segment-clip" type="button" title="Clip vanaf hier (1 minuut)" @click="clipFromSegment(segment)">✂ Clip</button>
                    </div>
                </article>
            </div>
            <div v-else class="detail-empty">
                <h3>{{ search ? 'Geen transcriptsegmenten gevonden' : 'Nog geen transcript beschikbaar' }}</h3>
                <p>{{ search ? `Geen resultaten voor “${search}”. Probeer een andere zoekterm.` : 'Deze stream heeft nog geen transcriptsegmenten.' }}</p>
            </div>
            <div v-if="segments.total > 0" class="transcript-pagination">
                <span class="pagination-summary">{{ segments.from }}–{{ segments.to }} van {{ segments.total }}</span>
                <nav aria-label="Paginering transcript">
                    <Link v-for="link in pagination" :key="link.label + (link.url || '')" class="pagination-link" :class="{ 'pagination-active': link.active, 'pagination-disabled': !link.url }" :href="link.url || '#'" preserve-scroll><span v-html="link.label" /></Link>
                </nav>
            </div>
        </section>
    </AppLayout>
</template>
