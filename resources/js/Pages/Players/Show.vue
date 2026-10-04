<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import AddStreamModal from '../../Components/AddStreamModal.vue';
import EmptyState from '../../Components/EmptyState.vue';
import FlashMessages from '../../Components/FlashMessages.vue';
import PlayerAvatar from '../../Components/PlayerAvatar.vue';
import StatCard from '../../Components/StatCard.vue';
import StreamsTable from '../../Components/StreamsTable.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { formatDate, type PlayerOption, type PlayerStats, type Stream } from '../../types/streams';

defineProps<{ player: PlayerStats; streams: Stream[]; players: PlayerOption[] }>();
const showForm = ref(false);
</script>

<template>
    <Head :title="player.name" />
    <AppLayout :title="player.name" eyebrow="Player">
        <Link class="back-link" href="/dashboard">← Dashboard</Link>
        <section class="player-hero">
            <PlayerAvatar :name="player.name" size="lg" />
            <div class="player-hero-text"><p class="section-kicker">Player</p><h2 class="page-section-title">{{ player.name }}</h2><p class="muted-copy">{{ player.last_stream_at ? 'Last stream ' + formatDate(player.last_stream_at) : 'No streams yet' }}</p></div>
            <button class="primary-button" type="button" @click="showForm = true"><span>＋</span> Add Stream</button>
        </section>
        <FlashMessages />
        <section class="stats-grid" aria-label="Player statistics">
            <StatCard label="Streams" :value="String(player.streams_count)" icon="play" accent="blue" :meta="player.streams_count ? 'Recorded streams' : 'No streams yet'" />
            <StatCard label="Transcribed" :value="player.transcribed_streams_count + '/' + player.streams_count" icon="users" accent="violet" meta="Completed transcripts" />
            <StatCard label="Events" :value="String(player.events_count)" icon="spark" accent="amber" meta="Extracted events" />
            <StatCard label="Processing" :value="player.active_streams_count ? String(player.active_streams_count) : 'Idle'" icon="clock" accent="green" meta="Queued or running jobs" />
        </section>
        <StreamsTable v-if="streams.length" class="player-streams" :streams="streams" :show-player="false" />
        <div v-else class="standalone-panel player-streams"><EmptyState :title="'No streams for ' + player.name + ' yet'" description="Add a stream to start transcribing and extracting events." /></div>
        <AddStreamModal v-model:open="showForm" :players="players" :default-player-id="player.id" />
    </AppLayout>
</template>
