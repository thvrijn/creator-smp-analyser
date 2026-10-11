<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import EmptyState from '../Components/EmptyState.vue';
import FlashMessages from '../Components/FlashMessages.vue';
import PlayerAvatar from '../Components/PlayerAvatar.vue';
import AppLayout from '../Layouts/AppLayout.vue';
import { eventTypeLabel } from '../composables/streamStatus';
import { formatDate, type PlayerRef } from '../types/streams';

type Step = 'download' | 'transcribe' | 'extract';
type StreamRef = { id: number; title: string; started_at: string | null; ended_at: string | null; player: PlayerRef };
type StepState = { ready: number; failed: number; streams: (StreamRef & { failed: boolean; error: string | null })[] };
type Episode = { title: string; from: string; to: string; state: 'done' | 'current' | 'upcoming' };

const props = defineProps<{
    season: { name: string; starts_on: string; ends_on: string; days: number; day: number; episodes: Episode[] };
    steps: Record<Step, StepState>;
    live_count: number;
    vods_synced_at: string | null;
    expiring: (StreamRef & { expires_at: string; retention_days: number; retention_known: boolean; has_audio: boolean; clips_count: number })[];
    expiring_count: number;
    expired_without_audio: number;
    chapters: (StreamRef & { summary: string | null; events_count: number; events: { id: number; type: string; title: string }[]; met: PlayerRef[] })[];
    teams: { name: string; players: number; died: number }[];
    deaths: { id: number; died_at: string; message: string; player: PlayerRef & { team: string | null } }[];
}>();

const stepInfo: Record<Step, { title: string; verb: string; hint: string }> = {
    download: { title: 'Audio ophalen', verb: 'ophalen', hint: "Twitch-VOD's zonder audio" },
    transcribe: { title: 'Transcriberen', verb: 'transcriberen', hint: 'Audio zonder transcript' },
    extract: { title: 'Analyseren', verb: 'analyseren', hint: 'Transcripts zonder verhaal en events' },
};
const stepOrder: Step[] = ['download', 'transcribe', 'extract'];

// "Te doen" changes as jobs finish; the rest of the page only changes after an analysis or a sync.
let pollTimer: number | undefined;
let stopped = false;
const poll = () => router.reload({ only: ['steps', 'live_count', 'expiring', 'expiring_count'], onFinish: () => { if (!stopped) pollTimer = window.setTimeout(poll, 20000); } });
onMounted(() => { pollTimer = window.setTimeout(poll, 20000); });
onBeforeUnmount(() => { stopped = true; window.clearTimeout(pollTimer); });

const syncing = ref(false);
const syncAllVods = () => router.post('/players/sync-vods', {}, { preserveScroll: true, onStart: () => { syncing.value = true; }, onFinish: () => { syncing.value = false; } });
const queueing = ref<Step | null>(null);
const queueAll = (step: Step) => {
    const count = props.steps[step].ready;
    const extra = step === 'download' ? '' : ' Ze wachten op een vrije worker.';
    if (!window.confirm(`${count} ${count === 1 ? 'stream' : 'streams'} ${stepInfo[step].verb}? De oudste gaan eerst.${extra}`)) return;
    router.post('/dashboard/queue/' + step, {}, { preserveScroll: true, onStart: () => { queueing.value = step; }, onFinish: () => { queueing.value = null; } });
};

const shortDate = (value: string | null) => value ? new Intl.DateTimeFormat('nl-NL', { weekday: 'short', day: 'numeric', month: 'short' }).format(new Date(value)) : '—';
const dayRange = (from: string, to: string) => from === to ? shortDate(from) : shortDate(from) + ' – ' + shortDate(to);
const timeLeft = (value: string) => {
    const hours = (new Date(value).getTime() - Date.now()) / 3_600_000;
    if (hours < 1) return 'binnen een uur';
    if (hours < 36) return 'over ' + Math.round(hours) + ' uur';
    return 'over ' + Math.round(hours / 24) + ' dagen';
};

const seasonLabel = computed(() => {
    const { day, days } = props.season;
    if (day === 0) return 'Het seizoen begint ' + shortDate(props.season.starts_on);
    if (day > days) return 'Het seizoen is voorbij';
    return `Dag ${day} van ${days}`;
});
const daysToFinale = computed(() => Math.max(0, props.season.days - props.season.day));
const currentEpisode = computed(() => props.season.episodes.find((episode) => episode.state === 'current'));
// The finale is one day; it gets a minimum width so its label fits.
const episodeDays = (episode: Episode) => Math.max(2.5, (new Date(episode.to).getTime() - new Date(episode.from).getTime()) / 86_400_000 + 1);
const todayInEpisode = (episode: Episode) => {
    const start = new Date(episode.from + 'T00:00:00').getTime();
    const length = (new Date(episode.to + 'T00:00:00').getTime() - start) / 86_400_000 + 1;
    return Math.min(100, Math.max(0, ((Date.now() - start) / 86_400_000 / length) * 100));
};
const totalBacklog = computed(() => stepOrder.reduce((sum, step) => sum + props.steps[step].ready + props.steps[step].failed, 0));
</script>

<template>
    <Head title="Dashboard" />
    <AppLayout title="Dashboard" eyebrow="Werkplek">
        <div class="streams-toolbar">
            <div>
                <p class="section-kicker">{{ season.name }}</p>
                <h2 class="page-section-title">{{ seasonLabel }}</h2>
                <p class="muted-copy"><template v-if="currentEpisode">{{ currentEpisode.title }} · </template><template v-if="season.day > 0 && season.day <= season.days">{{ daysToFinale === 0 ? 'vandaag is de finale' : 'nog ' + daysToFinale + ' ' + (daysToFinale === 1 ? 'dag' : 'dagen') + ' tot de finale' }}</template></p>
            </div>
            <div class="dashboard-sync">
                <button class="secondary-button" type="button" :disabled="syncing" title="Haalt de nieuwe Twitch-VOD's van alle spelers op (zonder audio)." @click="syncAllVods"><span class="button-icon" aria-hidden="true">⟳</span> {{ syncing ? 'Syncen…' : "Alle VOD's syncen" }}</button>
                <span class="muted-inline">{{ vods_synced_at ? 'Laatst gesynct ' + formatDate(vods_synced_at) : 'Nog nooit gesynct' }}</span>
            </div>
        </div>
        <FlashMessages />

        <Link class="season-bar" href="/episodes" aria-label="Afleveringen van het seizoen">
            <div v-for="episode in season.episodes" :key="episode.title" class="season-episode" :class="'season-episode-' + episode.state" :style="{ flexGrow: episodeDays(episode) }">
                <span class="season-episode-track"><span v-if="episode.state !== 'upcoming'" :style="{ width: (episode.state === 'done' ? 100 : todayInEpisode(episode)) + '%' }" /></span>
                <strong>{{ episode.title }}</strong>
                <span>{{ dayRange(episode.from, episode.to) }}</span>
            </div>
        </Link>

        <div class="dashboard-columns">
            <section class="analysis-card" aria-labelledby="todo-title">
                <div class="analysis-card-head"><h3 id="todo-title">Te doen</h3><span class="muted-inline">{{ totalBacklog ? totalBacklog + ' streams wachten op een stap' : 'Alles is bij' }}</span></div>
                <div class="todo-steps">
                    <article v-for="step in stepOrder" :key="step" class="todo-step">
                        <div class="todo-step-head">
                            <div>
                                <h4>{{ stepInfo[step].title }}</h4>
                                <p>{{ stepInfo[step].hint }}<template v-if="step === 'download' && live_count"> · {{ live_count }} nog live</template></p>
                            </div>
                            <span class="todo-count" :class="{ 'todo-count-zero': !steps[step].ready }">{{ steps[step].ready }}</span>
                        </div>
                        <span v-if="steps[step].failed" class="status-chip todo-failed">{{ steps[step].failed }} mislukt</span>
                        <ul v-if="steps[step].streams.length" class="todo-streams">
                            <li v-for="stream in steps[step].streams" :key="stream.id">
                                <Link :href="'/streams/' + stream.id" class="todo-stream" :title="stream.error ?? stream.title">
                                    <PlayerAvatar :name="stream.player.name" :photo-url="stream.player.photo_url" size="sm" />
                                    <span><strong>{{ stream.player.name }}</strong><span>{{ shortDate(stream.started_at) }} · {{ stream.title }}</span><em v-if="stream.failed">Mislukt{{ stream.error ? ': ' + stream.error : '' }}</em></span>
                                </Link>
                            </li>
                        </ul>
                        <p v-else class="todo-empty">Niets te doen.</p>
                        <button class="secondary-button todo-button" type="button" :disabled="!steps[step].ready || queueing !== null" @click="queueAll(step)">{{ queueing === step ? 'Bezig…' : steps[step].ready ? 'Alle ' + steps[step].ready + ' ' + stepInfo[step].verb : 'Niets klaar' }}</button>
                    </article>
                </div>
            </section>

            <section class="analysis-card expiring-card" :class="{ 'expiring-card-alert': expiring_count }" aria-labelledby="expiring-title">
                <div class="analysis-card-head"><h3 id="expiring-title">Verloopt binnenkort</h3><span v-if="expiring_count" class="status-chip todo-failed">{{ expiring_count }}</span></div>
                <p class="muted-copy expiring-intro">Twitch bewaart VOD's 7 dagen, bij Affiliates 14 en bij Partners 60. Daarna zijn de audio en de clips weg.</p>
                <ul v-if="expiring.length" class="todo-streams">
                    <li v-for="stream in expiring" :key="stream.id">
                        <Link :href="'/streams/' + stream.id" class="todo-stream">
                            <PlayerAvatar :name="stream.player.name" :photo-url="stream.player.photo_url" size="sm" />
                            <span>
                                <strong>{{ stream.player.name }} <span class="expiring-when" :title="stream.retention_known ? 'Twitch bewaart VOD\'s van dit kanaal ' + stream.retention_days + ' dagen.' : 'Bewaartermijn onbekend: uitgegaan van ' + stream.retention_days + ' dagen.'">verloopt {{ timeLeft(stream.expires_at) }}{{ stream.retention_known ? '' : '?' }}</span></strong>
                                <span>{{ shortDate(stream.started_at) }} · {{ stream.title }}</span>
                                <em>{{ stream.has_audio ? '' : 'Audio nog niet opgehaald' }}{{ !stream.has_audio && stream.clips_count ? ' · ' : '' }}{{ stream.clips_count ? stream.clips_count + (stream.clips_count === 1 ? ' clip' : ' clips') + ' nog niet gedownload' : '' }}</em>
                            </span>
                        </Link>
                    </li>
                </ul>
                <p v-else class="todo-empty">Geen VOD's die de komende 3 dagen verlopen en nog iets nodig hebben.</p>
                <p v-if="expiring_count > expiring.length" class="muted-inline">En nog {{ expiring_count - expiring.length }} meer.</p>
                <p v-if="expiring.some((stream) => !stream.retention_known)" class="expiring-unknown">? Van deze kanalen is de bewaartermijn nog onbekend, dus wordt uitgegaan van 7 dagen. Sync de VOD's om de echte termijn op te halen.</p>
                <p v-if="expired_without_audio" class="expiring-lost">{{ expired_without_audio }} {{ expired_without_audio === 1 ? 'VOD is' : "VOD's zijn" }} al verlopen zonder dat de audio is opgehaald.</p>
            </section>
        </div>

        <section class="dashboard-section" aria-labelledby="story-title">
            <div class="dashboard-section-head"><h3 id="story-title" class="section-kicker">Nieuw in het verhaal</h3><Link class="inline-link" href="/events">Alle events →</Link></div>
            <div v-if="chapters.length" class="chapter-grid">
                <article v-for="chapter in chapters" :key="chapter.id" class="chapter-card">
                    <Link :href="'/streams/' + chapter.id" class="chapter-head">
                        <PlayerAvatar :name="chapter.player.name" :photo-url="chapter.player.photo_url" size="sm" />
                        <span><strong>{{ chapter.player.name }}</strong><span>{{ shortDate(chapter.started_at) }} · {{ chapter.title }}</span></span>
                    </Link>
                    <p v-if="chapter.summary" class="chapter-summary">{{ chapter.summary }}</p>
                    <p v-else class="chapter-summary muted-copy">Nog geen verhaal geschreven voor deze stream.</p>
                    <ul v-if="chapter.events.length" class="chapter-events">
                        <li v-for="event in chapter.events" :key="event.id"><Link :href="'/streams/' + chapter.id + '?event=' + event.id"><span class="event-type" :class="'event-type-' + event.type">{{ eventTypeLabel(event.type) }}</span>{{ event.title }}</Link></li>
                    </ul>
                    <div class="chapter-foot">
                        <span v-if="chapter.met.length" class="chapter-met">Ontmoet
                            <Link v-for="player in chapter.met" :key="player.id" :href="'/players/' + player.id" :title="player.name"><PlayerAvatar :name="player.name" :photo-url="player.photo_url" size="sm" /></Link>
                        </span>
                        <Link class="inline-link" :href="'/streams/' + chapter.id">{{ chapter.events_count }} {{ chapter.events_count === 1 ? 'event' : 'events' }} →</Link>
                    </div>
                </article>
            </div>
            <div v-else class="standalone-panel dashboard-empty"><EmptyState title="Nog geen geanalyseerde streams" description="Zodra een analyse klaar is, staat het verhaal van die stream hier, met de belangrijkste events en wie de speler tegenkwam." /></div>
        </section>

        <section class="dashboard-section" aria-labelledby="server-title">
            <div class="dashboard-section-head"><h3 id="server-title" class="section-kicker">Stand op de server</h3></div>
            <div class="dashboard-columns">
                <div class="team-grid">
                    <article v-for="team in teams" :key="team.name" class="analysis-card team-card">
                        <h3>Team {{ team.name }}</h3>
                        <p class="team-alive"><strong>{{ team.players - team.died }}</strong> van {{ team.players }} nog nooit gestorven</p>
                        <div class="progress-track"><span :style="{ width: (team.players ? ((team.players - team.died) / team.players) * 100 : 0) + '%' }" /></div>
                        <p class="muted-inline">{{ team.died }} {{ team.died === 1 ? 'speler' : 'spelers' }} gestorven</p>
                    </article>
                    <p class="muted-inline team-note">Een dood in het eerste speeluur (grace period) is niet definitief, maar telt hier wel mee: welke dat waren staat niet in de lijst.</p>
                </div>
                <div class="analysis-card">
                    <div class="analysis-card-head"><h3>Laatste deaths</h3></div>
                    <ul v-if="deaths.length" class="death-list">
                        <li v-for="death in deaths" :key="death.id">
                            <Link :href="'/players/' + death.player.id" class="todo-stream">
                                <PlayerAvatar :name="death.player.name" :photo-url="death.player.photo_url" size="sm" />
                                <span><strong>{{ death.player.name }} <span v-if="death.player.team" class="team-chip">{{ death.player.team }}</span></strong><span>{{ death.message }}</span><span>{{ formatDate(death.died_at) }}</span></span>
                            </Link>
                        </li>
                    </ul>
                    <p v-else class="todo-empty">Nog niemand gestorven.</p>
                </div>
            </div>
        </section>
    </AppLayout>
</template>
