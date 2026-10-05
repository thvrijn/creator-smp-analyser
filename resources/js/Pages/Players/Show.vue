<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AddStreamModal from '../../Components/AddStreamModal.vue';
import EmptyState from '../../Components/EmptyState.vue';
import FlashMessages from '../../Components/FlashMessages.vue';
import PlayerAvatar from '../../Components/PlayerAvatar.vue';
import PlayerFormModal from '../../Components/PlayerFormModal.vue';
import StatCard from '../../Components/StatCard.vue';
import StreamsTable from '../../Components/StreamsTable.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { formatDate, type PlayerOption, type PlayerStats, type Stream } from '../../types/streams';

const props = defineProps<{ player: PlayerStats; streams: Stream[]; players: PlayerOption[] }>();
const showForm = ref(false);
const showPlayerForm = ref(false);
const syncing = ref(false);
const syncVods = () => router.post(`/players/${props.player.id}/sync-vods`, {}, { preserveScroll: true, onStart: () => { syncing.value = true; }, onFinish: () => { syncing.value = false; } });
</script>

<template>
    <Head :title="player.name" />
    <AppLayout :title="player.name" eyebrow="Speler">
        <Link class="back-link" href="/dashboard">← Dashboard</Link>
        <section class="player-hero">
            <PlayerAvatar :name="player.name" :photo-url="player.photo_url" size="lg" />
            <div class="player-hero-text"><p class="section-kicker">Speler</p><h2 class="page-section-title">{{ player.name }}</h2><p class="muted-copy">{{ player.last_stream_at ? 'Laatste stream ' + formatDate(player.last_stream_at) : 'Nog geen streams' }}<span v-if="player.vods_synced_at"> · VOD's gesynct {{ formatDate(player.vods_synced_at) }}</span></p><a v-if="player.twitch_login" class="twitch-link" :href="'https://www.twitch.tv/' + player.twitch_login" target="_blank" rel="noopener noreferrer">twitch.tv/{{ player.twitch_login }} ↗</a></div>
            <div class="button-row"><button class="secondary-button" type="button" :disabled="!player.twitch_login || syncing" :title="player.twitch_login ? 'Haalt de nieuwe VOD\'s van twitch.tv/' + player.twitch_login + ' op (zonder video).' : 'Deze speler heeft geen Twitch-kanaal.'" @click="syncVods"><span class="button-icon" aria-hidden="true">⟳</span> {{ syncing ? 'Syncen…' : "VOD's syncen" }}</button><button class="secondary-button" type="button" @click="showPlayerForm = true">Bewerken</button><button class="primary-button" type="button" @click="showForm = true"><span>＋</span> Stream toevoegen</button></div>
        </section>
        <FlashMessages />
        <section class="stats-grid" aria-label="Statistieken speler">
            <StatCard label="Streams" :value="String(player.streams_count)" icon="play" accent="blue" :meta="player.streams_count ? 'Opgenomen streams' : 'Nog geen streams'" />
            <StatCard label="Getranscribeerd" :value="player.transcribed_streams_count + '/' + player.streams_count" icon="users" accent="violet" meta="Afgeronde transcripts" />
            <StatCard label="Events" :value="String(player.events_count)" icon="spark" accent="amber" meta="Gevonden events" />
            <StatCard label="Verwerking" :value="player.active_streams_count ? String(player.active_streams_count) : 'Inactief'" icon="clock" accent="green" meta="Jobs in wachtrij of bezig" />
        </section>
        <StreamsTable v-if="streams.length" class="player-streams" :streams="streams" :show-player="false" />
        <div v-else class="standalone-panel player-streams"><EmptyState :title="'Nog geen streams voor ' + player.name" description="Voeg een stream toe om te transcriberen en events te extraheren." /></div>
        <AddStreamModal v-model:open="showForm" :players="players" :default-player-id="player.id" />
        <PlayerFormModal v-model:open="showPlayerForm" :player="player" />
    </AppLayout>
</template>
