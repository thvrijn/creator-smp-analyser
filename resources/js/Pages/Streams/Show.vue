<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import FlashMessages from '../../Components/FlashMessages.vue';
import AudioPlayer from '../../Components/AudioPlayer.vue';
import TwitchPlayer from '../../Components/TwitchPlayer.vue';
import PlayerAvatar from '../../Components/PlayerAvatar.vue';
import SpeakerEditor from '../../Components/SpeakerEditor.vue';
import SpeakerText from '../../Components/SpeakerText.vue';
import { matchReason, type Speaker } from '../../composables/speakers';
import { streamDurationLabel, cancelTask, canCancel, isCancelling, downloadAudio, formatBytes, downloadLabel, eventBadgeClass, eventProgressDetails, isDownloadActive, retranscribe, eventExtractionLabel, eventTypeLabel, extractButtonLabel, extractEvents, formatSeconds, isEventExtractionActive, isTranscriptionActive, refreshStatus, stageLabel, stalledMessage, transcribe, transcribeButtonLabel, transcriptionBadgeClass, transcriptionLabel , type CancellableTask } from '../../composables/streamStatus';
import AppLayout from '../../Layouts/AppLayout.vue';
import { formatDate, type Stream } from '../../types/streams';

// story_*: the storyline of the stream written by the analysis, and a summary per part (file times).
type StoryPart = { start_time: number; end_time: number; summary: string };
type StreamDetail = Stream & { duration_seconds: number | null; segment_count: number; story_summary: string | null; story_players: string[]; story_parts: StoryPart[] };
type StreamEvent = { id: number; type: string; title: string; description: string; start_time: number; end_time: number; confidence: number; segment_count: number };
// original_text: what speech recognition wrote, when the text was corrected by hand.
type Segment = { id: number; start_time: number; end_time: number; text: string; speaker: number | null; original_text: string | null };
type PaginationLink = { url: string | null; label: string; active: boolean };
type Clip = { id: number; event_id: number | null; title: string; start_seconds: number; end_seconds: number };
// The same moment in another player's stream (SharedMoments): their event at that time, or else the time in their file.
type SharedMoment = { stream_id: number; stream_title: string; player: { id: number; name: string; photo_url: string | null }; event_id: number | null; event_title: string | null; at: number; reasons: ('voice' | 'named' | 'voice_there' | 'named_there')[] };
type Pagination = { data: Segment[]; current_page: number; last_page: number; per_page: number; total: number; from: number | null; to: number | null };

const props = defineProps<{ stream: StreamDetail; events: StreamEvent[]; clips: Clip[]; selected_event_id: number | null; highlighted_segment_ids: number[]; segments: Pagination; pagination: PaginationLink[]; search: string; speaker_filter: number | null; speakers: Speaker[]; players: { id: number; name: string }[]; shared_moments: Record<number, SharedMoment[]> }>();
const stream = reactive<StreamDetail>({ ...props.stream });
watch(() => props.stream, (value) => { Object.assign(stream, value); }, { deep: true });
const search = ref(props.search);
watch(() => props.search, (value) => { search.value = value; });

const streamUrl = '/streams/' + props.stream.id;
const isHighlighted = (segment: Segment) => props.highlighted_segment_ids.includes(segment.id);
// Without a name given by hand, speaker 0 (speaks the most, almost always the streamer) is the stream's player and the
// others are numbered voices. Names, merges and per-segment fixes are saved on the server (SpeakerController).
const speakerLabel = (speaker: number) => props.speakers.find((item) => item.speaker === speaker)?.name ?? (speaker === 0 ? stream.player.name : `Spreker ${speaker}`);
const speakerClass = (speaker: number) => 'segment-speaker-' + (speaker === 0 ? 'main' : speaker % 6);
const findSpeaker = (speaker: number) => props.speakers.find((candidate) => candidate.speaker === speaker);
const isMatched = (speaker: number) => findSpeaker(speaker)?.source === 'matched';
const speakerTitle = (speaker: number) => {
    const item = findSpeaker(speaker);
    if (item?.source === 'matched') return 'Herkend: ' + matchReason(item) + '. Klik om te bevestigen of te wijzigen.';
    if (item?.named) return 'Spreker ' + speaker + ', benoemd door jou. Klik om te wijzigen.';
    return (speaker === 0 ? 'Spreekt het meest in deze stream, waarschijnlijk de streamer.' : 'Een andere stem in deze stream.') + ' Klik om te wijzigen.';
};

const editingSpeaker = ref<number | null>(null);
// From a speaker chip in the Transcript tab the editor sits above the transcript; from "Spreker n" in an event or the
// storyline (SpeakerText) it opens as a pop-up, on any tab.
const editorInModal = ref(false);
const editSpeaker = (item: Speaker) => {
    editorInModal.value = false;
    editingSpeaker.value = editingSpeaker.value === item.speaker ? null : item.speaker;
};
// at: the moment the text is about (file time), to listen back who it is.
const pickedAt = ref<number | null>(null);
const pickSpeaker = (speaker: number, at: number | null = null) => {
    editorInModal.value = true;
    pickedAt.value = at;
    editingSpeaker.value = speaker;
};
const editedSpeaker = computed(() => editingSpeaker.value === null ? undefined : findSpeaker(editingSpeaker.value));
const speakerOptions = { preserveScroll: true, preserveState: true, only: ['speakers', 'segments', 'flash', 'errors'] };

// Correcting the text of a line (✎ or a double click): Enter saves, Escape cancels, and the recognised text can be put back.
const editingTextId = ref<number | null>(null);
const textDraft = ref('');
const startEditText = async (segment: Segment) => {
    editingTextId.value = segment.id;
    textDraft.value = segment.text;
    await nextTick();
    document.querySelector<HTMLTextAreaElement>('.segment-text-editor textarea')?.focus();
};
const saveSegmentText = (segment: Segment, text: string) => {
    if (text.trim() === '' || text.trim() === segment.text) { editingTextId.value = null; return; }
    router.put('/segments/' + segment.id + '/text', { text }, { preserveScroll: true, preserveState: true, only: ['segments', 'flash', 'errors'], onSuccess: () => { editingTextId.value = null; } });
};

// One segment to another speaker: a small menu on its speaker label.
const editingSegmentId = ref<number | null>(null);
const openSegmentSpeaker = async (segment: Segment) => {
    editingSegmentId.value = segment.id;
    await nextTick();
    document.querySelector<HTMLSelectElement>('.segment-speaker-select')?.focus();
};
const moveSegment = (segment: Segment, value: string) => {
    editingSegmentId.value = null;
    const speaker = value === 'none' ? null : value === 'new' ? 'new' : Number(value);
    if (speaker === segment.speaker) return;
    router.put('/segments/' + segment.id + '/speaker', { speaker }, speakerOptions);
};
const sharedMoments = (event: StreamEvent) => props.shared_moments[event.id] ?? [];
const momentUrl = (moment: SharedMoment) => '/streams/' + moment.stream_id + '?' + new URLSearchParams(moment.event_id !== null ? { event: String(moment.event_id) } : { tab: 'transcript', at: String(moment.at) });
const momentTitle = (moment: SharedMoment) => {
    const them = moment.player.name;
    const us = stream.player.name;
    const reasons = moment.reasons.map((reason) => ({
        voice: 'je hoort ' + them + ' in dit event',
        named: them + ' wordt genoemd',
        voice_there: 'bij ' + them + ' hoor je ' + us,
        named_there: them + ' noemt ' + us,
    })[reason]);
    return 'Zelfde moment in "' + moment.stream_title + '": ' + reasons.join(', ') + '.';
};
const selectedEvent = computed(() => props.events.find((event) => event.id === props.selected_event_id));

// The tab lives in the URL (?tab=), so links and the back button work. Without one, a selected
// event, search or page means the transcript is what you came for.
type Tab = 'story' | 'events' | 'transcript' | 'clips';
const tabs: Tab[] = ['story', 'events', 'transcript', 'clips'];
const tabLabels: Record<Tab, string> = { story: 'Verhaal', events: 'Events', transcript: 'Transcript', clips: 'Clips' };
const hasStory = computed(() => Boolean(stream.story_summary) || stream.story_parts.length > 0);
const page = usePage();
const query = computed(() => Object.fromEntries(new URL(page.url, window.location.origin).searchParams));
const activeTab = computed<Tab>(() => tabs.includes(query.value.tab as Tab) ? query.value.tab as Tab : (query.value.clip ? 'clips' : query.value.event || query.value.at || query.value.search || query.value.page ? 'transcript' : hasStory.value ? 'story' : 'events'));
const tabCount = (tab: Tab) => ({ story: stream.story_parts.length, events: props.events.length, transcript: stream.segment_count, clips: clips.value.length })[tab];
// The storyline in paragraphs, and the players in it linked to their page when the name is known.
const storyParagraphs = computed(() => (stream.story_summary ?? '').split(/\n\s*\n/).map((paragraph) => paragraph.trim()).filter(Boolean));
const storyPlayer = (name: string) => props.players.find((player) => player.name.toLowerCase() === name.toLowerCase());
const transcriptAt = (seconds: number) => streamUrl + '?' + new URLSearchParams({ tab: 'transcript', at: String(seconds) });
const tabUrl = (tab: Tab) => streamUrl + '?' + new URLSearchParams({ ...query.value, tab });

// The transcript filters: a search term and/or one speaker (?speaker=n, a click on their chip).
const transcriptQuery = (speaker: number | null) => ({ tab: 'transcript', ...(search.value.trim() ? { search: search.value.trim() } : {}), ...(speaker !== null ? { speaker: String(speaker) } : {}) });
const submitSearch = () => { router.get(streamUrl, transcriptQuery(props.speaker_filter), { preserveState: true, preserveScroll: true, replace: true }); };
// A click on a speaker shows only what they say; clicking the same speaker again shows everyone.
const filterSpeaker = (speaker: number | null) => {
    editingSpeaker.value = null;
    router.get(streamUrl, transcriptQuery(speaker === props.speaker_filter ? null : speaker), { preserveState: true, preserveScroll: true });
};
const filteredSpeaker = computed(() => props.speaker_filter === null ? undefined : findSpeaker(props.speaker_filter));
const clearSelection = () => { router.get(streamUrl, { tab: 'transcript' }, { preserveState: true, preserveScroll: true, replace: true }); };
// Selecting an event opens it in the transcript; clicking the selected event again deselects it.
const selectEvent = (event: StreamEvent) => {
    const deselect = props.selected_event_id === event.id;
    router.get(streamUrl, deselect ? {} : { event: event.id }, { preserveState: true, preserveScroll: true, only: ['selected_event_id', 'highlighted_segment_ids', 'segments', 'pagination', 'search'] });
};

// Bring the first highlighted segment into view (the page scrolls, not the list).
const transcriptList = ref<HTMLElement | null>(null);
const scrollToHighlight = async () => {
    await nextTick();
    transcriptList.value?.querySelector<HTMLElement>('.segment-highlight')?.scrollIntoView({ block: 'center' });
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
    const segment = transcriptList.value?.querySelector<HTMLElement>('.segment-playing');
    if (!segment) return;
    // Follow only while the listener is reading along: the segment just moved past the bottom of the screen. Somebody
    // who scrolled elsewhere is left alone.
    const { top, bottom } = segment.getBoundingClientRect();
    if (bottom > window.innerHeight - 40 && top < window.innerHeight + 160) segment.scrollIntoView({ block: 'center', behavior: 'smooth' });
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
// Like a large title in an Apple app: once the stream's title scrolls away, a compact bar with the title and status
// slides in above the sticky audio player and tabs. --stream-sticky-bottom is where those bars end, for content that
// sticks below them (the clip player) and for scrolling a new transcript page back to its top.
const heroTitle = ref<HTMLElement | null>(null);
const stickyBar = ref<HTMLElement | null>(null);
const compact = ref(false);
const COMPACT_HEIGHT = 58;
const stickyHeight = ref(0);
const stickyBottom = computed(() => (compact.value ? COMPACT_HEIGHT : 0) + stickyHeight.value);
const scrollToTop = () => window.scrollTo({ top: 0, behavior: 'smooth' });
const panel = ref<HTMLElement | null>(null);
watch(() => props.segments.from, async () => {
    await nextTick();
    if (props.highlighted_segment_ids.length || !panel.value) return;
    const top = panel.value.getBoundingClientRect().top;
    if (top < stickyBottom.value) window.scrollBy({ top: top - stickyBottom.value - 12 });
});
let titleObserver: IntersectionObserver | undefined;
let stickyObserver: ResizeObserver | undefined;

onMounted(() => {
    void scrollToHighlight();
    pollTimer = window.setInterval(() => { void poll(); }, 2000);
    titleObserver = new IntersectionObserver(([entry]) => { compact.value = !entry.isIntersecting && entry.boundingClientRect.top < 0; });
    if (heroTitle.value) titleObserver.observe(heroTitle.value);
    stickyObserver = new ResizeObserver(() => { stickyHeight.value = stickyBar.value?.offsetHeight ?? 0; });
    if (stickyBar.value) stickyObserver.observe(stickyBar.value);
});
onBeforeUnmount(() => {
    if (pollTimer !== undefined) window.clearInterval(pollTimer);
    titleObserver?.disconnect();
    stickyObserver?.disconnect();
});
// Queued, waiting or running jobs of this stream that can be cancelled (or are being cancelled).
const cancellable = computed(() => ([
    { task: 'video_download', label: 'Audio ophalen' },
    { task: 'transcription', label: 'Transcriptie' },
    { task: 'event_extraction', label: 'Analyse' },
] as { task: CancellableTask; label: string }[]).filter((item) => canCancel(stream, item.task) || isCancelling(stream, item.task)));
const formatTime = (value: string) => new Intl.DateTimeFormat('nl-NL', { hour: '2-digit', minute: '2-digit' }).format(new Date(value));
</script>

<template>
    <Head :title="stream.title" />
    <AppLayout :title="stream.title" eyebrow="Stream">
        <div class="stream-page" :style="{ '--stream-sticky-bottom': stickyBottom + 'px' }">
        <header class="stream-compact" :class="{ 'stream-compact-visible': compact }" :inert="!compact || undefined">
            <button class="stream-compact-title" type="button" title="Naar boven" @click="scrollToTop">
                <PlayerAvatar :name="stream.player.name" :photo-url="stream.player.photo_url" size="sm" />
                <span><strong>{{ stream.title }}</strong><span class="transcription-stage">{{ stream.player.name }} · {{ formatDate(stream.started_at) }}</span></span>
            </button>
            <div class="stream-compact-statuses">
                <span v-if="stream.twitch_video_id && !stream.video_path" class="status-pair"><span class="transcription-stage">Audio</span><span class="transcription-badge" :class="'transcription-' + audioState.badge">{{ audioState.label }}</span></span>
                <span class="status-pair"><span class="transcription-stage">Transcript</span><span class="transcription-badge" :class="transcriptionBadgeClass(stream)">{{ isTranscriptionActive(stream) ? Math.round(stream.transcription_progress) + '%' : transcriptionLabel(stream.transcription_status) }}</span></span>
                <span class="status-pair"><span class="transcription-stage">Events</span><span class="transcription-badge" :class="eventBadgeClass(stream)">{{ isEventExtractionActive(stream) && stream.event_extraction_progress !== null ? stream.event_extraction_progress + '%' : eventExtractionLabel(stream.event_extraction_status) }}</span></span>
            </div>
        </header>
        <Link class="back-link" :href="'/players/' + stream.player.id">← {{ stream.player.name }}</Link>
        <section class="stream-hero">
            <div class="stream-hero-text">
                <p class="section-kicker">Stream</p>
                <h2 ref="heroTitle" class="page-section-title">{{ stream.title }}</h2>
                <p class="muted-copy"><Link class="inline-link" :href="'/players/' + stream.player.id">{{ stream.player.name }}</Link> · {{ formatDate(stream.started_at) }}<template v-if="stream.ended_at"> – {{ formatTime(stream.ended_at) }}</template><template v-if="streamDurationLabel(stream)"> · <strong class="stream-length-inline">{{ streamDurationLabel(stream) }}</strong><template v-if="stream.status === 'Live'"> live</template></template><template v-if="stream.duration_seconds"> · {{ formatSeconds(stream.duration_seconds) }} getranscribeerd</template> · {{ stream.segment_count }} segmenten · {{ events.length }} events</p>
                <div class="stream-hero-statuses">
                    <span v-if="stream.twitch_video_id" class="status-pair"><span class="transcription-stage">Audio</span><span class="transcription-badge" :class="'transcription-' + audioState.badge">{{ audioState.label }}</span></span>
                    <span class="status-pair"><span class="transcription-stage">Transcript</span><span class="transcription-badge" :class="transcriptionBadgeClass(stream)">{{ transcriptionLabel(stream.transcription_status) }}</span><span v-if="isTranscriptionActive(stream)" class="transcription-stage">{{ stageLabel(stream.transcription_stage) }} · {{ Math.round(stream.transcription_progress) }}%</span></span>
                    <span class="status-pair"><span class="transcription-stage">Events</span><span class="transcription-badge" :class="eventBadgeClass(stream)">{{ eventExtractionLabel(stream.event_extraction_status) }}</span><span v-if="isEventExtractionActive(stream) && stream.event_extraction_progress !== null" class="transcription-stage">{{ stream.event_extraction_progress }}% · {{ eventProgressDetails(stream) }}</span></span>
                </div>
                <div v-if="!stream.video_path && stream.video_download_status === 'processing' && !stream.video_download_stalled" class="progress-track"><span :style="{ width: stream.video_download_progress + '%' }" /></div>
                <div v-if="isEventExtractionActive(stream)" class="progress-track" :class="{ 'progress-indeterminate': stream.event_extraction_progress === null }"><span :style="stream.event_extraction_progress === null ? {} : { width: stream.event_extraction_progress + '%' }" /></div>
                <p v-if="stream.worker_name" class="transcription-stage">Draait op worker {{ stream.worker_name }}</p>
                <div v-if="cancellable.length > 0" class="stream-cancel-row">
                    <template v-for="item in cancellable" :key="item.task">
                        <span v-if="isCancelling(stream, item.task)" class="transcription-stage">{{ item.label }} wordt geannuleerd…</span>
                        <button v-else class="cancel-link" type="button" @click="cancelTask(stream, item.task)">✕ {{ item.label }} annuleren</button>
                    </template>
                </div>
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

        <div ref="stickyBar" class="stream-sticky" :class="{ 'stream-sticky-compact': compact }">
        <div v-if="stream.video_path" class="stream-audio">
            <span class="transcription-stage">{{ stream.video_mime_type?.startsWith('audio/') ? 'Audio' : 'Video' }}<template v-if="stream.video_file_size !== null"> · {{ formatBytes(stream.video_file_size) }}</template></span>
            <AudioPlayer ref="audio" :src="streamUrl + '/audio'" @time="audioTime = $event" @playing="audioPlaying = $event" />
        </div>

        <nav class="stream-tabs" aria-label="Onderdelen van de stream">
            <Link v-for="tab in tabs" :key="tab" class="stream-tab" :class="{ 'stream-tab-active': activeTab === tab }" :href="tabUrl(tab)" :aria-current="activeTab === tab ? 'page' : undefined" preserve-state preserve-scroll>
                {{ tabLabels[tab] }}<span class="stream-tab-count">{{ tabCount(tab) }}</span>
            </Link>
        </nav>
        </div>

        <section v-if="activeTab === 'story'" ref="panel" class="detail-panel stream-panel story-panel" aria-label="Verhaal">
            <template v-if="hasStory">
                <div v-if="storyParagraphs.length" class="story-summary">
                    <p v-for="(paragraph, index) in storyParagraphs" :key="index"><SpeakerText :text="paragraph" :speakers="speakers" @pick="pickSpeaker($event)" /></p>
                </div>
                <div v-if="stream.story_players.length" class="story-players">
                    <span class="transcription-stage">Spelers in dit verhaal</span>
                    <template v-for="name in stream.story_players" :key="name">
                        <Link v-if="storyPlayer(name)" class="story-player" :href="'/players/' + storyPlayer(name)!.id">{{ name }}</Link>
                        <span v-else class="story-player">{{ name }}</span>
                    </template>
                </div>
                <div v-if="stream.story_parts.length" class="story-parts">
                    <h3 class="section-kicker">Per deel van de stream</h3>
                    <div v-for="part in stream.story_parts" :key="part.start_time" class="story-part">
                        <span class="event-time">{{ formatSeconds(part.start_time) }} – {{ formatSeconds(part.end_time) }}</span>
                        <p><SpeakerText :text="part.summary" :speakers="speakers" @pick="pickSpeaker($event, part.start_time)" /></p>
                        <span class="story-part-actions">
                            <button v-if="stream.video_path" class="event-clip-button" type="button" title="Afspelen vanaf hier" @click="playFrom(part.start_time)">▶</button>
                            <Link class="event-clip-button" :href="transcriptAt(part.start_time)" preserve-scroll>Transcript</Link>
                        </span>
                    </div>
                </div>
            </template>
            <div v-else class="detail-empty">
                <h3>Nog geen verhaal</h3>
                <p>{{ stream.transcription_status === 'completed' ? 'Klik op “Analyseren”: de analyse schrijft op wat er in de game gebeurde, naast de events.' : 'Transcribeer eerst de stream en analyseer hem daarna.' }}</p>
            </div>
        </section>

        <section v-else-if="activeTab === 'events'" ref="panel" class="detail-panel stream-panel" aria-label="Events">
            <div v-if="events.length" class="event-list">
                <div v-for="event in events" :key="event.id" class="event-entry">
                    <div class="event-main">
                    <button type="button" class="event-item" :class="{ 'event-item-active': event.id === selected_event_id }" :aria-pressed="event.id === selected_event_id" @click="selectEvent(event)">
                        <span class="event-item-top"><span v-if="event.type !== 'other'" class="event-type" :class="'event-type-' + event.type">{{ eventTypeLabel(event.type) }}</span><span class="event-time event-time-end">{{ formatSeconds(event.start_time) }} – {{ formatSeconds(event.end_time) }}</span></span>
                        <span class="event-title"><SpeakerText :text="event.title" :speakers="speakers" @pick="pickSpeaker($event, event.start_time)" /></span>
                        <span class="event-description"><SpeakerText :text="event.description" :speakers="speakers" @pick="pickSpeaker($event, event.start_time)" /></span>
                        <span class="event-meta">{{ Math.round(event.confidence * 100) }}% zekerheid · {{ event.segment_count }} {{ event.segment_count === 1 ? 'segment' : 'segmenten' }}</span>
                    </button>
                    <span class="event-actions">
                        <button v-if="stream.video_path" class="event-clip-button" type="button" title="Afspelen vanaf dit event" @click="playFrom(event.start_time)">▶ Afspelen</button>
                        <Link v-if="eventClip(event)" class="event-clip-button event-clip-done" :href="clipUrl(eventClip(event)!)" preserve-state preserve-scroll>✓ Clip</Link>
                        <button v-else class="event-clip-button" type="button" @click="clipFromEvent(event)">＋ Clip</button>
                    </span>
                    </div>
                    <div v-if="sharedMoments(event).length" class="event-moments">
                        <span class="transcription-stage">Zelfde moment bij</span>
                        <Link v-for="moment in sharedMoments(event)" :key="moment.stream_id" class="event-moment" :href="momentUrl(moment)" :title="momentTitle(moment)">
                            <PlayerAvatar :name="moment.player.name" :photo-url="moment.player.photo_url" size="sm" /><strong>{{ moment.player.name }}</strong><span>{{ moment.event_title ?? 'transcript op ' + formatSeconds(moment.at) }}</span>
                        </Link>
                    </div>
                </div>
            </div>
            <div v-else class="detail-empty">
                <h3>Nog geen events</h3>
                <p>{{ stream.transcription_status === 'completed' ? 'Klik op “Analyseren” om events in dit transcript te vinden.' : 'Transcribeer eerst de stream en analyseer hem daarna.' }}</p>
            </div>
        </section>

        <section v-else-if="activeTab === 'clips'" ref="panel" class="detail-panel stream-panel clips-panel" aria-label="Clips">
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

        <section v-else ref="panel" class="detail-panel stream-panel" aria-label="Transcript">
            <div v-if="selectedEvent" class="panel-heading"><p class="muted-copy">Gemarkeerd: <strong>{{ selectedEvent.title }}</strong></p><button class="secondary-button" type="button" @click="clearSelection">Wissen</button></div>
            <form class="transcript-search" @submit.prevent="submitSearch">
                <label class="sr-only" for="transcript-search">Transcript doorzoeken</label>
                <input id="transcript-search" v-model="search" type="search" placeholder="Zoek in transcript…" />
                <button class="secondary-button" type="submit">Zoeken</button>
            </form>
            <div v-if="speakers.length" class="speaker-bar">
                <span class="transcription-stage">Sprekers</span>
                <button v-for="item in speakers" :key="item.speaker" class="speaker-chip" :class="[speakerClass(item.speaker), { 'speaker-chip-active': speaker_filter === item.speaker, 'speaker-matched': item.source === 'matched' }]" type="button" :aria-pressed="speaker_filter === item.speaker" :title="(item.source === 'matched' ? 'Herkend: ' + matchReason(item) + '. ' : '') + 'Spreker ' + item.speaker + ' · ' + item.segment_count + ' zinnen. ' + (speaker_filter === item.speaker ? 'Klik om iedereen weer te tonen.' : 'Klik om alleen deze spreker te tonen.')" @click="filterSpeaker(item.speaker)">
                    {{ item.name }}<template v-if="item.source === 'matched'">?</template><span>{{ formatSeconds(item.seconds) }}</span>
                </button>
            </div>
            <div v-if="speaker_filter !== null" class="speaker-filter">
                <p class="muted-copy">Alleen <strong>{{ filteredSpeaker?.name ?? 'Spreker ' + speaker_filter }}</strong>: {{ segments.total }} {{ segments.total === 1 ? 'zin' : 'zinnen' }}</p>
                <button v-if="filteredSpeaker" class="secondary-button" type="button" @click="editSpeaker(filteredSpeaker)">Wie is dit?</button>
                <button class="secondary-button" type="button" @click="filterSpeaker(null)">Iedereen tonen</button>
            </div>
            <SpeakerEditor v-if="editedSpeaker && !editorInModal" :stream-url="streamUrl" :speaker="editedSpeaker" :speakers="speakers" :players="players" :streamer-name="stream.player.name" @close="editingSpeaker = null" />
            <div v-if="segments.data.length" ref="transcriptList" class="transcript-list">
                <article v-for="segment in segments.data" :key="segment.id" class="transcript-segment" :class="{ 'segment-highlight': isHighlighted(segment), 'segment-playing': segment.id === playingSegmentId }">
                    <div class="segment-time">{{ formatSeconds(segment.start_time) }}</div>
                    <div class="segment-copy"><select v-if="editingSegmentId === segment.id" class="segment-speaker-select" :value="segment.speaker === null ? 'none' : String(segment.speaker)" aria-label="Spreker van deze zin" @change="moveSegment(segment, ($event.target as HTMLSelectElement).value)" @blur="editingSegmentId = null">
                        <option v-for="item in speakers" :key="item.speaker" :value="String(item.speaker)">{{ item.name }}</option>
                        <option value="new">Nieuwe spreker</option>
                        <option value="none">Geen spreker</option>
                    </select><button v-else-if="segment.speaker !== null" class="segment-speaker" :class="[speakerClass(segment.speaker), { 'speaker-matched': isMatched(segment.speaker) }]" type="button" :title="speakerTitle(segment.speaker)" @click="openSegmentSpeaker(segment)">{{ speakerLabel(segment.speaker) }}<template v-if="isMatched(segment.speaker)">?</template></button><button v-else-if="speakers.length" class="segment-speaker segment-speaker-none" type="button" title="Geen spreker. Klik om er een te kiezen." @click="openSegmentSpeaker(segment)">?</button><form v-if="editingTextId === segment.id" class="segment-text-editor" @submit.prevent="saveSegmentText(segment, textDraft)">
                        <textarea v-model="textDraft" rows="2" maxlength="2000" aria-label="Tekst van deze zin" @keydown.enter.exact.prevent="saveSegmentText(segment, textDraft)" @keydown.esc.prevent="editingTextId = null" />
                        <span class="segment-text-actions"><button class="primary-button" type="submit">Opslaan</button><button class="secondary-button" type="button" @click="editingTextId = null">Annuleren</button><button v-if="segment.original_text" class="secondary-button" type="button" @click="saveSegmentText(segment, segment.original_text)">Herkende tekst terugzetten</button><span class="transcription-stage">Enter: opslaan · Esc: annuleren</span></span>
                        <p v-if="segment.original_text" class="segment-original">Herkend als: {{ segment.original_text }}</p>
                    </form><p v-else title="Dubbelklik om te verbeteren" @dblclick="startEditText(segment)">{{ segment.text }}<span v-if="segment.original_text" class="segment-edited" :title="'Herkend als: ' + segment.original_text">aangepast</span></p><span>{{ formatSeconds(segment.end_time) }}</span></div>
                    <div class="segment-actions">
                        <button v-if="stream.video_path" class="segment-clip" type="button" title="Afspelen vanaf hier" @click="playFrom(segment.start_time)">▶</button>
                        <button class="segment-clip" type="button" title="Tekst verbeteren" @click="startEditText(segment)">✎</button>
                        <button class="segment-clip" type="button" title="Clip vanaf hier (1 minuut)" @click="clipFromSegment(segment)">✂ Clip</button>
                        <Link v-if="speaker_filter !== null || search" class="segment-clip" :href="transcriptAt(segment.start_time)" title="Deze zin in het hele gesprek bekijken" preserve-state>In gesprek</Link>
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
        <div v-if="editedSpeaker && editorInModal" class="modal-backdrop" role="presentation" @click.self="editingSpeaker = null">
            <SpeakerEditor class="speaker-editor-modal" :stream-url="streamUrl" :speaker="editedSpeaker" :speakers="speakers" :players="players" :streamer-name="stream.player.name" @close="editingSpeaker = null">
                <template v-if="pickedAt !== null">
                    <button v-if="stream.video_path" class="secondary-button" type="button" @click="playFrom(pickedAt)">▶ Luister vanaf dit moment</button>
                    <Link class="inline-link speaker-evidence" :href="transcriptAt(pickedAt)" @click="editingSpeaker = null">Bekijk in transcript ↗</Link>
                </template>
            </SpeakerEditor>
        </div>
        </div>
    </AppLayout>
</template>
