<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import EmptyState from '../../Components/EmptyState.vue';
import PlayerAvatar from '../../Components/PlayerAvatar.vue';
import PlayerFormModal from '../../Components/PlayerFormModal.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import type { EditablePlayer } from '../../types/streams';

type Player = EditablePlayer & { streams_count: number; created_at: string };

const props = defineProps<{ players: Player[] }>();
const page = usePage<{ flash?: { success?: string; error?: string } }>();
const showForm = ref(false);
const editingPlayer = ref<Player | null>(null);
const successMessage = computed(() => page.props.flash?.success);
const errorMessage = computed(() => page.props.flash?.error);

const formatDate = (value: string) => new Intl.DateTimeFormat('nl-NL', {
    day: '2-digit', month: 'short', year: 'numeric',
}).format(new Date(value));

const playerUrl = (player: Player) => `/players/${player.id}`;
// The whole row opens the player page; links and buttons inside it keep their own action.
const openPlayer = (event: MouseEvent, player: Player) => {
    if ((event.target as HTMLElement).closest('a, button')) return;
    router.visit(playerUrl(player));
};

const openForm = (player: Player | null) => {
    editingPlayer.value = player;
    showForm.value = true;
};

const removePlayer = (player: Player) => {
    if (player.streams_count > 0) {
        window.alert(`${player.name} kan niet worden verwijderd. Deze speler heeft ${player.streams_count} stream${player.streams_count === 1 ? '' : 's'}. Verwijder de streams eerst of koppel ze aan een andere speler.`);
        return;
    }

    if (window.confirm(`${player.name} verwijderen?`)) router.delete(playerUrl(player));
};
</script>

<template>
    <Head title="Spelers" />
    <AppLayout title="Spelers" eyebrow="Analyse">
        <div class="streams-toolbar">
            <div>
                <p class="section-kicker">Analyse</p>
                <h2 class="page-section-title">Je spelers</h2>
                <p class="muted-copy">Beheer de creators in je streambibliotheek.</p>
            </div>
            <button class="primary-button" type="button" @click="openForm(null)"><span>＋</span> Speler toevoegen</button>
        </div>

        <div v-if="successMessage" class="success-banner" role="status">{{ successMessage }}</div>
        <div v-if="errorMessage" class="error-banner" role="alert">{{ errorMessage }}</div>

        <div v-if="props.players.length" class="streams-panel">
            <div class="streams-table-wrap">
                <table class="streams-table players-table">
                    <thead><tr><th>Speler</th><th>Streams</th><th>Aangemaakt</th><th><span class="sr-only">Acties</span></th></tr></thead>
                    <tbody>
                        <tr v-for="player in props.players" :key="player.id" class="stream-row" @click="openPlayer($event, player)">
                            <td><Link class="player-cell" :href="playerUrl(player)"><PlayerAvatar :name="player.name" :photo-url="player.photo_url" size="sm" /><span class="stream-title">{{ player.name }}</span></Link></td>
                            <td>{{ player.streams_count }}</td>
                            <td>{{ formatDate(player.created_at) }}</td>
                            <td class="action-cell"><button class="text-action" type="button" @click="openForm(player)">Bewerken</button><button class="delete-button" type="button" @click="removePlayer(player)">Verwijderen</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div v-else class="standalone-panel"><EmptyState title="Nog geen spelers" description="Voeg je eerste speler toe om de streambibliotheek te ordenen." /></div>

        <PlayerFormModal v-model:open="showForm" :player="editingPlayer" />
    </AppLayout>
</template>
