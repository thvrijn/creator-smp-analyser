<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import FlashMessages from '../../Components/FlashMessages.vue';
import AudioPlayer from '../../Components/AudioPlayer.vue';
import TwitchPlayer from '../../Components/TwitchPlayer.vue';
import PlayerAvatar from '../../Components/PlayerAvatar.vue';
import { streamDurationLabel, cancelTask, canCancel, isCancelling, downloadAudio, formatBytes, downloadLabel, eventBadgeClass, eventProgressDetails, isDownloadActive, retranscribe, eventExtractionLabel, eventTypeLabel, extractButtonLabel, extractEvents, formatSeconds, isEventExtractionActive, isTranscriptionActive, refreshStatus, stageLabel, stalledMessage, transcribe, transcribeButtonLabel, transcriptionBadgeClass, transcriptionLabel , type CancellableTask } from '../../composables/streamStatus';
import AppLayout from '../../Layouts/AppLayout.vue';
import { formatDate, type Stream } from '../../types/streams';

type StreamDetail = Stream & { duration_seconds: number | null; segment_count: number };
type StreamEvent = { id: number; type: string; title: string; description: string; start_time: number; end_time: number; confidence: number; segment_count: number };
type Segment = { id: number; start_time: number; end_time: number; text: string; speaker: number | null };
type PaginationLink = { url: string | null; label: string; active: boolean };
type Clip = { id: number; event_id: number | null; title: string; start_seconds: number; end_seconds: number };
// source: named/unknown by hand, matched (by text: the same sentences as that player in their own stream, see
// SpeakerTextMatches; or by voice, VoiceProfiles, with its similarity), or default (0 = the streamer).
type Speaker = { speaker: number; name: string; source: 'named' | 'unknown' | 'matched' | 'default'; named: boolean; player_id: number | null; label: string | null; matched_by: 'text' | 'voice' | null; similarity: number | null; text_hits: number | null; text_stream_id: number | null; seconds: number; segment_count: number };
// The same moment in another player's stream (SharedMoments): their event at that time, or else the time in their file.
type SharedMoment = { stream_id: number; stream_title: string; player: { id: number; name: string; photo_url: string | null }; event_id: number | null; event_title: string | null; at: number; reasons: ('voice' | 'named' | 'voice_there' | 'named_there')[] };
type Pagination = { data: Segment[]; current_page: number; last_page: number; per_page: number; total: number; from: number | null; to: number | null };

const props = defineProps<{ stream: StreamDetail; events: StreamEvent[]; clips: Clip[]; selected_event_id: number | null; highlighted_segment_ids: number[]; segments: Pagination; pagination: PaginationLink[]; search: string; speakers: Speaker[]; players: { id: number; name: string }[]; shared_moments: Record<number, SharedMoment[]> }>();
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
const similarityLabel = (item: Speaker) => Math.round((item.similarity ?? 0) * 100) + '% gelijk';
// Why a speaker is shown as a player: what they say, or how they sound.
const matchReason = (item: Speaker) => item.matched_by === 'text'
    ? 'zegt ' + item.text_hits + ' keer hetzelfde als ' + item.name + ' op dat moment in diens eigen stream'
    : 'stem ' + similarityLabel(item);
const speakerTitle = (speaker: number) => {
    const item = findSpeaker(speaker);
    if (item?.source === 'matched') return 'Herkend: ' + matchReason(item) + '. Klik om te bevestigen of te wijzigen.';
    if (item?.named) return 'Spreker ' + speaker + ', benoemd door jou. Klik om te wijzigen.';
    return (speaker === 0 ? 'Spreekt het meest in deze stream, waarschijnlijk de streamer.' : 'Een andere stem in deze stream.') + ' Klik om te wijzigen.';
};

const editingSpeaker = ref<number | null>(null);
const speakerForm = reactive({ choice: '' as string, label: '', mergeInto: '' as string });
const editSpeaker = (item: Speaker) => {
    editingSpeaker.value = editingSpeaker.value === item.speaker ? null : item.speaker;
    speakerForm.choice = item.source === 'unknown' ? 'unknown' : item.source !== 'named' ? '' : item.player_id !== null ? String(item.player_id) : 'label';
    speakerForm.label = item.label ?? '';
    speakerForm.mergeInto = '';
};
const speakerOptions = { preserveScroll: true, preserveState: true, only: ['speakers', 'segments', 'flash', 'errors'] };
const saveSpeaker = () => {
    if (editingSpeaker.value === null) return;
    const body = speakerForm.choice === 'label' ? { label: speakerForm.label } : speakerForm.choice === 'unknown' ? { unknown: true } : { player_id: speakerForm.choice === '' ? null : Number(speakerForm.choice) };
    router.put(streamUrl + '/speakers/' + editingSpeaker.value, body, { ...speakerOptions, onSuccess: () => { editingSpeaker.value = null; } });
};
const editedSpeaker = computed(() => editingSpeaker.value === null ? undefined : findSpeaker(editingSpeaker.value));
const automaticLabel = computed(() => {
    const item = editedSpeaker.value;
    if (!item) return 'Automatisch';
    if (item.source === 'matched') return 'Automatisch: herkend als ' + item.name + ' (' + matchReason(item) + ')';
    return item.speaker === 0 ? 'Automatisch: de streamer (' + stream.player.name + ')' : 'Automatisch: herkennen aan de stem';
});
// A voice match is a guess until confirmed; confirming makes it a known voice of that player.
const confirmMatch = () => {
    const item = editedSpeaker.value;
    if (!item || item.player_id === null) return;
    router.put(streamUrl + '/speakers/' + item.speaker, { player_id: item.player_id }, { ...speakerOptions, onSuccess: () => { editingSpeaker.value = null; } });
};
const mergeSpeaker = () => {
    if (editingSpeaker.value === null || speakerForm.mergeInto === '') return;
    const from = speakerLabel(editingSpeaker.value);
    const into = speakerLabel(Number(speakerForm.mergeInto));
    if (!window.confirm('Alles van "' + from + '" bij "' + into + '" zetten? Dat kan niet ongedaan worden gemaakt.')) return;
    router.post(streamUrl + '/speakers/' + editingSpeaker.value + '/merge', { into: Number(speakerForm.mergeInto) }, { ...speakerOptions, onSuccess: () => { editingSpeaker.value = null; } });
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
type Tab = 'events' | 'transcript' | 'clips';
const tabs: Tab[] = ['events', 'transcript', 'clips'];
const tabLabels: Record<Tab, string> = { events: 'Events', transcript: 'Transcript', clips: 'Clips' };
const page = usePage();
const query = computed(() => Object.fromEntries(new URL(page.url, window.location.origin).searchParams));
const activeTab = computed<Tab>(() => tabs.includes(query.value.tab as Tab) ? query.value.tab as Tab : (query.value.clip ? 'clips' : query.value.event || query.value.at || query.value.search || query.value.page ? 'transcript' : 'events'));
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
    <AppLayout :title="stream.title" eyebrow="Stream" fill>
        <Link class="back-link" :href="'/players/' + stream.player.id">← {{ stream.player.name }}</Link>
        <section class="stream-hero">
            <div class="stream-hero-text">
                <p class="section-kicker">Stream</p>
                <h2 class="page-section-title">{{ stream.title }}</h2>
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
                    <div class="event-main">
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
            <div v-if="speakers.length" class="speaker-bar">
                <span class="transcription-stage">Sprekers</span>
                <button v-for="item in speakers" :key="item.speaker" class="speaker-chip" :class="[speakerClass(item.speaker), { 'speaker-chip-active': editingSpeaker === item.speaker, 'speaker-matched': item.source === 'matched' }]" type="button" :title="(item.source === 'matched' ? 'Herkend: ' + matchReason(item) + '. ' : '') + 'Spreker ' + item.speaker + ' · ' + item.segment_count + ' zinnen. Klik om te benoemen of samen te voegen.'" @click="editSpeaker(item)">
                    {{ item.name }}<template v-if="item.source === 'matched'">?</template><span>{{ formatSeconds(item.seconds) }}</span>
                </button>
            </div>
            <form v-if="editingSpeaker !== null" class="speaker-editor" @submit.prevent="saveSpeaker">
                <div class="form-field"><label for="speaker-name">Wie is spreker {{ editingSpeaker }}?</label>
                    <select id="speaker-name" v-model="speakerForm.choice">
                        <option value="">{{ automaticLabel }}</option>
                        <option value="unknown">Onbekend (niet herkennen)</option>
                        <option value="label">Andere naam…</option>
                        <option v-for="player in players" :key="player.id" :value="String(player.id)">{{ player.name }}</option>
                    </select>
                </div>
                <div v-if="speakerForm.choice === 'label'" class="form-field"><label for="speaker-label">Naam</label><input id="speaker-label" v-model="speakerForm.label" type="text" maxlength="60" placeholder="bijv. een gast of de chat-TTS" required /></div>
                <button class="primary-button" type="submit">Opslaan</button>
                <button v-if="editedSpeaker?.source === 'matched'" class="secondary-button" type="button" @click="confirmMatch">✓ Klopt, dit is {{ editedSpeaker.name }}</button>
                <Link v-if="editedSpeaker?.matched_by === 'text' && editedSpeaker.text_stream_id" class="inline-link speaker-evidence" :href="'/streams/' + editedSpeaker.text_stream_id">Stream van {{ editedSpeaker.name }} bekijken ↗</Link>
                <div class="speaker-merge">
                    <div class="form-field"><label for="speaker-merge">Is dezelfde persoon als</label>
                        <select id="speaker-merge" v-model="speakerForm.mergeInto"><option value="">Kies een spreker…</option><option v-for="item in speakers.filter((candidate) => candidate.speaker !== editingSpeaker)" :key="item.speaker" :value="String(item.speaker)">{{ item.name }}</option></select>
                    </div>
                    <button class="secondary-button" type="button" :disabled="speakerForm.mergeInto === ''" @click="mergeSpeaker">Samenvoegen</button>
                </div>
                <button class="modal-close" type="button" aria-label="Sluiten" @click="editingSpeaker = null">×</button>
            </form>
            <div v-if="segments.data.length" ref="transcriptList" class="transcript-list transcript-list-scroll">
                <article v-for="segment in segments.data" :key="segment.id" class="transcript-segment" :class="{ 'segment-highlight': isHighlighted(segment), 'segment-playing': segment.id === playingSegmentId }">
                    <div class="segment-time">{{ formatSeconds(segment.start_time) }}</div>
                    <div class="segment-copy"><select v-if="editingSegmentId === segment.id" class="segment-speaker-select" :value="segment.speaker === null ? 'none' : String(segment.speaker)" aria-label="Spreker van deze zin" @change="moveSegment(segment, ($event.target as HTMLSelectElement).value)" @blur="editingSegmentId = null">
                        <option v-for="item in speakers" :key="item.speaker" :value="String(item.speaker)">{{ item.name }}</option>
                        <option value="new">Nieuwe spreker</option>
                        <option value="none">Geen spreker</option>
                    </select><button v-else-if="segment.speaker !== null" class="segment-speaker" :class="[speakerClass(segment.speaker), { 'speaker-matched': isMatched(segment.speaker) }]" type="button" :title="speakerTitle(segment.speaker)" @click="openSegmentSpeaker(segment)">{{ speakerLabel(segment.speaker) }}<template v-if="isMatched(segment.speaker)">?</template></button><button v-else-if="speakers.length" class="segment-speaker segment-speaker-none" type="button" title="Geen spreker. Klik om er een te kiezen." @click="openSegmentSpeaker(segment)">?</button><p>{{ segment.text }}</p><span>{{ formatSeconds(segment.end_time) }}</span></div>
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
