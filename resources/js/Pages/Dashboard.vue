<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import ActiveJobs from '../Components/ActiveJobs.vue';
import EmptyState from '../Components/EmptyState.vue';
import FlashMessages from '../Components/FlashMessages.vue';
import PlayerAvatar from '../Components/PlayerAvatar.vue';
import AppLayout from '../Layouts/AppLayout.vue';
import { formatDate, type PlayerStats, type Stream } from '../types/streams';

const props = defineProps<{ players: PlayerStats[]; active_streams: Stream[] }>();
// "Nu bezig" follows the running jobs: every 3 s while something runs, every 15 s to notice new ones. When a job
// finishes, the player cards (transcribed and event counts) are reloaded too.
let pollTimer: number | undefined;
let stopped = false;
const poll = () => {
    const before = props.active_streams.map((stream) => stream.id).join(',');
    router.reload({
        only: ['active_streams'],
        onSuccess: () => { if (props.active_streams.map((stream) => stream.id).join(',') !== before) router.reload({ only: ['players'] }); },
        onFinish: () => { if (!stopped) pollTimer = window.setTimeout(poll, props.active_streams.length ? 3000 : 15000); },
    });
};
onMounted(() => { pollTimer = window.setTimeout(poll, props.active_streams.length ? 3000 : 15000); });
onBeforeUnmount(() => { stopped = true; window.clearTimeout(pollTimer); });
const search = ref('');
const syncing = ref(false);
// Only the VOD list of every player with a Twitch channel; no video is downloaded.
const syncAllVods = () => router.post('/players/sync-vods', {}, { preserveScroll: true, onStart: () => { syncing.value = true; }, onFinish: () => { syncing.value = false; } });
// Case- and accent-insensitive, so "noel" finds "Noël Dekkers"; the Twitch channel counts too.
const normalize = (value: string) => value.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase();
const shownPlayers = computed(() => {
    const term = normalize(search.value.trim());
    return term ? props.players.filter((player) => normalize(player.name + ' ' + (player.twitch_login ?? '')).includes(term)) : props.players;
});
// Players with streams first; both groups keep the search filter and the name order.
const groups = computed(() => [
    { title: 'Met streams', players: shownPlayers.value.filter((player) => player.streams_count > 0) },
    { title: 'Nog geen streams', players: shownPlayers.value.filter((player) => player.streams_count === 0) },
].filter((group) => group.players.length));
const plural = (count: number, word: string) => count + ' ' + word + (count === 1 ? '' : 's');
</script>

<template>
    <Head title="Dashboard" />
    <AppLayout title="Dashboard" eyebrow="Spelers">
        <div class="streams-toolbar"><div><p class="section-kicker">Creator SMP 4</p><h2 class="page-section-title">Spelers</h2><p class="muted-copy">Kies een speler om de streams, transcripts en events te zien.</p></div><div class="button-row"><button class="secondary-button" type="button" :disabled="syncing" title="Haalt de nieuwe Twitch-VOD's van alle spelers op (zonder video)." @click="syncAllVods"><span class="button-icon" aria-hidden="true">⟳</span> {{ syncing ? 'Syncen…' : "Alle VOD's syncen" }}</button><Link class="secondary-button" href="/players">Spelers beheren</Link></div></div>
        <FlashMessages />
        <ActiveJobs v-if="active_streams.length" :streams="active_streams" />
        <template v-if="players.length">
        <label class="sr-only" for="player-search">Spelers zoeken</label>
        <input id="player-search" v-model="search" class="player-search" type="search" placeholder="Zoek speler…" />
        <section v-for="group in groups" :key="group.title" class="player-group" :aria-label="group.title">
            <h3 class="section-kicker player-group-title">{{ group.title }} <span>{{ group.players.length }}</span></h3>
            <div class="player-grid">
            <Link v-for="player in group.players" :key="player.id" class="player-card" :href="'/players/' + player.id">
                <div class="player-card-top">
                    <PlayerAvatar :name="player.name" :photo-url="player.photo_url" size="lg" />
                    <div class="player-card-heading">
                        <h3 class="player-card-name">{{ player.name }}</h3>
                        <p class="player-card-meta">{{ player.last_stream_at ? 'Laatste stream ' + formatDate(player.last_stream_at) : 'Nog geen streams' }}</p>
                    </div>
                    <span v-if="player.active_streams_count" class="player-card-activity"><span class="live-dot" />{{ player.active_streams_count }} bezig</span>
                </div>
                <dl class="player-card-stats">
                    <div><dt>Streams</dt><dd>{{ player.streams_count }}</dd></div>
                    <div><dt>Getranscribeerd</dt><dd>{{ player.transcribed_streams_count }}<span>/{{ player.streams_count }}</span></dd></div>
                    <div><dt>Events</dt><dd>{{ player.events_count }}</dd></div>
                </dl>
                <span class="player-card-link">{{ plural(player.streams_count, 'stream') }} <span>→</span></span>
            </Link>
            </div>
        </section>
        <div v-if="!groups.length" class="standalone-panel"><EmptyState title="Geen spelers gevonden" :description="'Geen speler past bij “' + search.trim() + '”.'" /></div>
        </template>
        <div v-else class="standalone-panel"><EmptyState title="Nog geen spelers" description="Voeg spelers toe op de pagina Spelers om hun streams te ordenen." /></div>
    </AppLayout>
</template>
