<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import AddStreamModal from '../../Components/AddStreamModal.vue';
import EmptyState from '../../Components/EmptyState.vue';
import FlashMessages from '../../Components/FlashMessages.vue';
import PlayerAvatar from '../../Components/PlayerAvatar.vue';
import PlayerFormModal from '../../Components/PlayerFormModal.vue';
import StreamsTable from '../../Components/StreamsTable.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { downloadAudio, downloadLabel, eventProgressDetails, eventTypeLabel, extractEvents, isDownloadActive, isEventExtractionActive, isTranscriptionActive, stageLabel, transcribe } from '../../composables/streamStatus';
import { formatDate, type PlayerOption, type PlayerRef, type PlayerStats, type Stream } from '../../types/streams';

type Chapter = { paragraphs: string[]; events_count: number; events: { id: number; type: string; title: string }[]; met: PlayerRef[]; vod_expires_at: string | null };
type Met = PlayerRef & { moments: number; streams: number };
const props = defineProps<{
    player: PlayerStats & { clips_count: number; retention_days: number | null; deaths: { id: number; died_at: string; message: string }[]; voice_streams: number };
    streams: Stream[];
    chapters: Record<number, Chapter>;
    met: Met[];
    players: PlayerOption[];
}>();

const showForm = ref(false);
const showPlayerForm = ref(false);
const syncing = ref(false);
const syncVods = () => router.post(`/players/${props.player.id}/sync-vods`, {}, { preserveScroll: true, onStart: () => { syncing.value = true; }, onFinish: () => { syncing.value = false; } });

// The tab lives in the URL (?tab=streams), so a reload or a link keeps it.
const tab = ref<'story' | 'streams'>(new URLSearchParams(window.location.search).get('tab') === 'streams' ? 'streams' : 'story');
const setTab = (value: 'story' | 'streams') => {
    tab.value = value;
    const url = new URL(window.location.href);
    if (value === 'story') url.searchParams.delete('tab'); else url.searchParams.set('tab', value);
    window.history.replaceState(window.history.state, '', url);
};

// The story reads from the first stream to the last: each stream continues the previous one.
const story = computed(() => [...props.streams].sort((a, b) => new Date(a.started_at).getTime() - new Date(b.started_at).getTime()));
const analysedCount = computed(() => props.streams.filter((stream) => stream.event_extraction_status === 'completed').length);
const expanded = reactive(new Set<number>());

// What a stream needs before it is a chapter, as one line with its button.
type Step = { label: string; tone: 'todo' | 'active' | 'failed' | 'live'; detail: string | null; action: (() => void) | null };
const nextStep = (stream: Stream): Step => {
    if (isDownloadActive(stream)) return { label: 'Audio ophalen', tone: 'active', detail: downloadLabel(stream), action: null };
    if (isTranscriptionActive(stream)) return { label: 'Transcriberen', tone: 'active', detail: stageLabel(stream.transcription_stage) + (stream.transcription_status === 'processing' ? ' · ' + Math.round(stream.transcription_progress) + '%' : ''), action: null };
    if (isEventExtractionActive(stream)) return { label: 'Analyseren', tone: 'active', detail: eventProgressDetails(stream) || (stream.event_extraction_status === 'processing' ? 'Bezig' : 'In wachtrij'), action: null };
    if (!stream.video_path) {
        if (!stream.twitch_video_id) return { label: 'Geen audio', tone: 'todo', detail: 'Upload de video of audio van deze stream.', action: null };
        if (!stream.ended_at) return { label: 'Nog live', tone: 'live', detail: "Haal de audio op als de stream voorbij is en de VOD's opnieuw gesynct zijn.", action: null };
        const failed = stream.video_download_status === 'failed';
        return { label: 'Audio ophalen', tone: failed ? 'failed' : 'todo', detail: failed ? stream.video_download_error : null, action: () => downloadAudio(stream) };
    }
    if (stream.transcription_status !== 'completed') {
        const failed = stream.transcription_status === 'failed';
        return { label: 'Transcriberen', tone: failed ? 'failed' : 'todo', detail: failed ? stream.transcription_error : null, action: () => transcribe(stream) };
    }
    const failed = stream.event_extraction_status === 'failed';
    return { label: 'Analyseren', tone: failed ? 'failed' : 'todo', detail: failed ? stream.event_extraction_error : null, action: () => extractEvents(stream) };
};

// A VOD that disappears within 3 days while its audio is not fetched yet.
const expiryWarning = (stream: Stream) => {
    const expires = props.chapters[stream.id]?.vod_expires_at;
    if (!expires || stream.video_path) return null;
    const hours = (new Date(expires).getTime() - Date.now()) / 3_600_000;
    if (hours <= 0) return 'De VOD is verlopen: de audio kan niet meer worden opgehaald.';
    if (hours > 72) return null;
    return 'De VOD verloopt ' + (hours < 36 ? 'over ' + Math.round(hours) + ' uur' : 'over ' + Math.round(hours / 24) + ' dagen') + '.';
};

const shortDate = (value: string | null) => value ? new Intl.DateTimeFormat('nl-NL', { weekday: 'short', day: 'numeric', month: 'short' }).format(new Date(value)) : '—';
const lastDeath = computed(() => props.player.deaths[props.player.deaths.length - 1] ?? null);

// While something runs for this player, the streams (and their story) are reloaded.
let pollTimer: number | undefined;
let stopped = false;
const anyActive = () => props.streams.some((stream) => isDownloadActive(stream) || isTranscriptionActive(stream) || isEventExtractionActive(stream));
const poll = () => router.reload({ only: ['streams', 'chapters', 'met', 'player'], onFinish: () => { if (!stopped) pollTimer = window.setTimeout(poll, anyActive() ? 4000 : 20000); } });
onMounted(() => { pollTimer = window.setTimeout(poll, anyActive() ? 4000 : 20000); });
onBeforeUnmount(() => { stopped = true; window.clearTimeout(pollTimer); });
</script>

<template>
    <Head :title="player.name" />
    <AppLayout :title="player.name" eyebrow="Speler">
        <Link class="back-link" href="/players">← Spelers</Link>
        <section class="player-hero">
            <PlayerAvatar :name="player.name" :photo-url="player.photo_url" size="lg" />
            <div class="player-hero-text">
                <p class="section-kicker">Speler<template v-if="player.team"> · Team {{ player.team }}</template></p>
                <h2 class="page-section-title">{{ player.name }}</h2>
                <p v-if="lastDeath" class="player-life player-life-dead" :title="player.deaths.length > 1 ? player.deaths.length + ' keer gestorven' : undefined">† Gestorven {{ formatDate(lastDeath.died_at) }}: {{ lastDeath.message }}</p>
                <p v-else class="player-life">Nog nooit gestorven</p>
                <p class="muted-copy player-hero-meta">
                    <a v-if="player.twitch_login" class="twitch-link" :href="'https://www.twitch.tv/' + player.twitch_login" target="_blank" rel="noopener noreferrer">twitch.tv/{{ player.twitch_login }} ↗</a>
                    <span v-if="player.twitch_login">{{ player.retention_days ? "VOD's blijven " + player.retention_days + ' dagen' : 'Bewaartermijn onbekend' }}</span>
                    <span v-if="player.vods_synced_at">Gesynct {{ formatDate(player.vods_synced_at) }}</span>
                </p>
            </div>
            <div class="button-row">
                <button class="secondary-button" type="button" :disabled="!player.twitch_login || syncing" :title="player.twitch_login ? 'Haalt de nieuwe VOD\'s van twitch.tv/' + player.twitch_login + ' op (zonder audio).' : 'Deze speler heeft geen Twitch-kanaal.'" @click="syncVods"><span class="button-icon" aria-hidden="true">⟳</span> {{ syncing ? 'Syncen…' : "VOD's syncen" }}</button>
                <button class="secondary-button" type="button" @click="showPlayerForm = true">Bewerken</button>
                <button class="primary-button" type="button" @click="showForm = true"><span>＋</span> Stream toevoegen</button>
            </div>
        </section>
        <FlashMessages />

        <div class="stream-tabs" role="tablist">
            <button class="stream-tab" type="button" role="tab" :aria-selected="tab === 'story'" :class="{ 'stream-tab-active': tab === 'story' }" @click="setTab('story')">Verhaal <span class="stream-tab-count" title="Geanalyseerde streams">{{ analysedCount }}/{{ streams.length }}</span></button>
            <button class="stream-tab" type="button" role="tab" :aria-selected="tab === 'streams'" :class="{ 'stream-tab-active': tab === 'streams' }" @click="setTab('streams')">Streams <span class="stream-tab-count">{{ streams.length }}</span></button>
        </div>

        <div v-if="tab === 'story'" class="player-layout">
            <div class="player-story">
                <template v-if="story.length">
                    <template v-for="(stream, index) in story" :key="stream.id">
                        <article v-if="stream.event_extraction_status === 'completed'" class="story-chapter">
                            <div class="story-chapter-marker"><span>{{ index + 1 }}</span></div>
                            <div class="story-chapter-body">
                                <Link :href="'/streams/' + stream.id" class="story-chapter-head"><span class="section-kicker">Hoofdstuk {{ index + 1 }} · {{ shortDate(stream.started_at) }}</span><strong>{{ stream.title }}</strong></Link>
                                <template v-if="chapters[stream.id]?.paragraphs.length">
                                    <p v-for="(paragraph, p) in (expanded.has(stream.id) ? chapters[stream.id].paragraphs : chapters[stream.id].paragraphs.slice(0, 1))" :key="p" class="chapter-summary">{{ paragraph }}</p>
                                    <button v-if="chapters[stream.id].paragraphs.length > 1" class="text-action story-more" type="button" @click="expanded.has(stream.id) ? expanded.delete(stream.id) : expanded.add(stream.id)">{{ expanded.has(stream.id) ? 'Minder' : 'Lees verder (' + (chapters[stream.id].paragraphs.length - 1) + ' meer)' }}</button>
                                </template>
                                <p v-else class="chapter-summary muted-copy">Geanalyseerd, maar er is geen verhaal geschreven: in deze stream gebeurde weinig in het spel.</p>
                                <ul v-if="chapters[stream.id]?.events.length" class="chapter-events">
                                    <li v-for="event in chapters[stream.id].events" :key="event.id"><Link :href="'/streams/' + stream.id + '?event=' + event.id"><span class="event-type" :class="'event-type-' + event.type">{{ eventTypeLabel(event.type) }}</span>{{ event.title }}</Link></li>
                                </ul>
                                <div class="chapter-foot">
                                    <span v-if="chapters[stream.id]?.met.length" class="chapter-met">Ontmoet
                                        <Link v-for="other in chapters[stream.id].met" :key="other.id" :href="'/players/' + other.id" :title="other.name"><PlayerAvatar :name="other.name" :photo-url="other.photo_url" size="sm" /></Link>
                                    </span>
                                    <span v-else />
                                    <Link class="inline-link" :href="'/streams/' + stream.id">{{ chapters[stream.id]?.events_count ?? 0 }} {{ chapters[stream.id]?.events_count === 1 ? 'event' : 'events' }} →</Link>
                                </div>
                            </div>
                        </article>
                        <article v-else class="story-gap" :class="'story-gap-' + nextStep(stream).tone">
                            <div class="story-chapter-marker"><span>{{ index + 1 }}</span></div>
                            <div class="story-gap-body">
                                <Link :href="'/streams/' + stream.id" class="story-gap-title"><span class="section-kicker">{{ shortDate(stream.started_at) }}</span><strong>{{ stream.title }}</strong></Link>
                                <p v-if="nextStep(stream).detail" class="story-gap-detail">{{ nextStep(stream).detail }}</p>
                                <p v-if="expiryWarning(stream)" class="story-gap-expiry">{{ expiryWarning(stream) }}</p>
                            </div>
                            <button v-if="nextStep(stream).action" class="secondary-button" type="button" @click="nextStep(stream).action?.()">{{ nextStep(stream).tone === 'failed' ? nextStep(stream).label + ' (opnieuw)' : nextStep(stream).label }}</button>
                            <span v-else class="status-chip story-gap-state">{{ nextStep(stream).tone === 'active' ? 'Bezig: ' + nextStep(stream).label.toLowerCase() : nextStep(stream).label }}</span>
                        </article>
                    </template>
                </template>
                <div v-else class="standalone-panel dashboard-empty"><EmptyState :title="'Nog geen streams voor ' + player.name" description="Sync de VOD's of voeg een stream toe. Elke stream wordt een hoofdstuk van het verhaal." /></div>
            </div>

            <aside class="player-aside">
                <section class="analysis-card">
                    <div class="analysis-card-head"><h3>Tegengekomen</h3></div>
                    <ul v-if="met.length" class="met-list">
                        <li v-for="other in met" :key="other.id"><Link :href="'/players/' + other.id" class="todo-stream"><PlayerAvatar :name="other.name" :photo-url="other.photo_url" size="sm" /><span><strong>{{ other.name }}</strong><span>{{ other.moments }} {{ other.moments === 1 ? 'moment' : 'momenten' }} · {{ other.streams }} {{ other.streams === 1 ? 'stream' : 'streams' }}</span></span></Link></li>
                    </ul>
                    <p v-else class="todo-empty">Nog niemand: dat blijkt uit geanalyseerde streams, uit wie er praat en wie er genoemd wordt.</p>
                </section>
                <section class="analysis-card">
                    <div class="analysis-card-head"><h3>Deaths</h3></div>
                    <ul v-if="player.deaths.length" class="met-list">
                        <li v-for="death in player.deaths" :key="death.id" class="aside-line"><strong>{{ death.message }}</strong><span>{{ formatDate(death.died_at) }}</span></li>
                    </ul>
                    <p v-else class="todo-empty">Nog nooit gestorven.</p>
                    <p v-if="player.deaths.length" class="muted-inline aside-note">Een dood in het eerste speeluur (grace period) is niet definitief.</p>
                </section>
                <section class="analysis-card">
                    <div class="analysis-card-head"><h3>Stem</h3></div>
                    <p class="aside-line"><strong>{{ player.voice_streams ? 'Bekend uit ' + player.voice_streams + ' ' + (player.voice_streams === 1 ? 'stream' : 'streams') : 'Nog niet bekend' }}</strong><span>{{ player.voice_streams ? 'Wordt herkend als spreker in streams van anderen.' : 'Bekend zodra een eigen stream met sprekers getranscribeerd is, of als een spreker naar deze speler wordt genoemd.' }}</span></p>
                </section>
                <section class="analysis-card">
                    <div class="analysis-card-head"><h3>Clips</h3><Link v-if="player.clips_count" class="inline-link" href="/video-builder">Videobouwer →</Link></div>
                    <p class="aside-line"><strong>{{ player.clips_count }} {{ player.clips_count === 1 ? 'clip' : 'clips' }}</strong><span>Maak clips met ＋ Clip bij een event op de streampagina.</span></p>
                </section>
            </aside>
        </div>

        <template v-else>
            <StreamsTable v-if="streams.length" class="player-streams" :streams="streams" :show-player="false" />
            <div v-else class="standalone-panel player-streams"><EmptyState :title="'Nog geen streams voor ' + player.name" description="Voeg een stream toe om te transcriberen en events te extraheren." /></div>
        </template>
        <AddStreamModal v-model:open="showForm" :players="players" :default-player-id="player.id" />
        <PlayerFormModal v-model:open="showPlayerForm" :player="player" />
    </AppLayout>
</template>
