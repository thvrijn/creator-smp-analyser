<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import EmptyState from '../../Components/EmptyState.vue';
import FlashMessages from '../../Components/FlashMessages.vue';
import PlayerAvatar from '../../Components/PlayerAvatar.vue';
import PlayerFormModal from '../../Components/PlayerFormModal.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { formatDate, type PlayerStats } from '../../types/streams';

const props = defineProps<{ players: PlayerStats[] }>();
const showForm = ref(false);
const editingPlayer = ref<PlayerStats | null>(null);
const menuFor = ref<number | null>(null);
const closeMenu = () => { menuFor.value = null; };
onMounted(() => document.addEventListener('click', closeMenu));
onBeforeUnmount(() => document.removeEventListener('click', closeMenu));

const search = ref('');
const team = ref<'' | 'Noord' | 'Zuid'>('');
// Case- and accent-insensitive, so "noel" finds "Noël Dekkers"; the Twitch channel counts too.
const normalize = (value: string) => value.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase();
const shownPlayers = computed(() => {
    const term = normalize(search.value.trim());
    return props.players.filter((player) => (!term || normalize(player.name + ' ' + (player.twitch_login ?? '')).includes(term)) && (!team.value || player.team === team.value));
});
// Players with streams first; both groups keep the filters and the name order.
const groups = computed(() => [
    { title: 'Met streams', players: shownPlayers.value.filter((player) => player.streams_count > 0) },
    { title: 'Nog geen streams', players: shownPlayers.value.filter((player) => player.streams_count === 0) },
].filter((group) => group.players.length));
const plural = (count: number, word: string) => count + ' ' + word + (count === 1 ? '' : 's');

const openForm = (player: PlayerStats | null) => {
    editingPlayer.value = player;
    showForm.value = true;
};
const removePlayer = (player: PlayerStats) => {
    if (player.streams_count > 0) {
        window.alert(`${player.name} kan niet worden verwijderd. Deze speler heeft ${plural(player.streams_count, 'stream')}. Verwijder de streams eerst of koppel ze aan een andere speler.`);
        return;
    }
    if (window.confirm(`${player.name} verwijderen?`)) router.delete('/players/' + player.id);
};
</script>

<template>
    <Head title="Spelers" />
    <AppLayout title="Spelers" eyebrow="Analyse">
        <div class="streams-toolbar">
            <div>
                <p class="section-kicker">Creator SMP 4</p>
                <h2 class="page-section-title">Spelers</h2>
                <p class="muted-copy">Kies een speler om de streams, transcripts en events te zien.</p>
            </div>
            <button class="primary-button" type="button" @click="openForm(null)"><span>＋</span> Speler toevoegen</button>
        </div>
        <FlashMessages />
        <template v-if="players.length">
            <div class="button-row player-filters">
                <label class="sr-only" for="player-search">Spelers zoeken</label>
                <input id="player-search" v-model="search" class="player-search" type="search" placeholder="Zoek speler…" />
                <label class="sr-only" for="player-team">Team</label>
                <select id="player-team" v-model="team" class="player-search player-team-filter"><option value="">Alle teams</option><option value="Noord">Team Noord</option><option value="Zuid">Team Zuid</option></select>
            </div>
            <section v-for="group in groups" :key="group.title" class="player-group" :aria-label="group.title">
                <h3 class="section-kicker player-group-title">{{ group.title }} <span>{{ group.players.length }}</span></h3>
                <div class="player-grid">
                    <div v-for="player in group.players" :key="player.id" class="player-card-wrap">
                        <Link class="player-card" :href="'/players/' + player.id">
                            <div class="player-card-top">
                                <PlayerAvatar :name="player.name" :photo-url="player.photo_url" size="lg" />
                                <div class="player-card-heading">
                                    <h3 class="player-card-name">{{ player.name }}</h3>
                                    <p class="player-card-meta">{{ player.last_stream_at ? 'Laatste stream ' + formatDate(player.last_stream_at) : 'Nog geen streams' }}</p>
                                    <span v-if="player.team" class="team-chip player-card-team">{{ player.team }}</span><span v-if="player.deaths_count" class="player-card-dead" :title="'Gestorven (' + player.deaths_count + '×). Een dood in de grace period is niet definitief.'">† gestorven</span>
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
                        <div class="header-menu player-card-menu" @click.stop>
                            <button class="icon-button" type="button" :aria-label="'Acties voor ' + player.name" aria-haspopup="menu" :aria-expanded="menuFor === player.id" @click="menuFor = menuFor === player.id ? null : player.id">⋯</button>
                            <div v-if="menuFor === player.id" class="profile-dropdown" role="menu" @click="closeMenu">
                                <button type="button" role="menuitem" @click="openForm(player)">Bewerken</button>
                                <button type="button" role="menuitem" @click="removePlayer(player)">Verwijderen</button>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
            <div v-if="!groups.length" class="standalone-panel"><EmptyState title="Geen spelers gevonden" description="Geen speler past bij deze zoekopdracht." /></div>
        </template>
        <div v-else class="standalone-panel"><EmptyState title="Nog geen spelers" description="Voeg je eerste speler toe, of vul ze met de CreatorSmpPlayerSeeder." /></div>
        <PlayerFormModal v-model:open="showForm" :player="editingPlayer" />
    </AppLayout>
</template>
