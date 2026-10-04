<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import EmptyState from '../Components/EmptyState.vue';
import PlayerAvatar from '../Components/PlayerAvatar.vue';
import AppLayout from '../Layouts/AppLayout.vue';
import { formatDate, type PlayerStats } from '../types/streams';

defineProps<{ players: PlayerStats[] }>();
const plural = (count: number, word: string) => count + ' ' + word + (count === 1 ? '' : 's');
</script>

<template>
    <Head title="Dashboard" />
    <AppLayout title="Dashboard" eyebrow="Players">
        <div class="streams-toolbar"><div><p class="section-kicker">Creator SMP 4</p><h2 class="page-section-title">Players</h2><p class="muted-copy">Choose a player to see their streams, transcripts and events.</p></div><Link class="secondary-button" href="/players">Manage players</Link></div>
        <section v-if="players.length" class="player-grid" aria-label="Players">
            <Link v-for="player in players" :key="player.id" class="player-card" :href="'/players/' + player.id">
                <div class="player-card-top">
                    <PlayerAvatar :name="player.name" size="lg" />
                    <span v-if="player.active_streams_count" class="player-card-activity"><span class="live-dot" />{{ player.active_streams_count }} processing</span>
                </div>
                <h3 class="player-card-name">{{ player.name }}</h3>
                <p class="player-card-meta">{{ player.last_stream_at ? 'Last stream ' + formatDate(player.last_stream_at) : 'No streams yet' }}</p>
                <dl class="player-card-stats">
                    <div><dt>Streams</dt><dd>{{ player.streams_count }}</dd></div>
                    <div><dt>Transcribed</dt><dd>{{ player.transcribed_streams_count }}<span>/{{ player.streams_count }}</span></dd></div>
                    <div><dt>Events</dt><dd>{{ player.events_count }}</dd></div>
                </dl>
                <span class="player-card-link">{{ plural(player.streams_count, 'stream') }} <span>→</span></span>
            </Link>
        </section>
        <div v-else class="standalone-panel"><EmptyState title="No players yet" description="Add players on the Players page to start organising their streams." /></div>
    </AppLayout>
</template>
