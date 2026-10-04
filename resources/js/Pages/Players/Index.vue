<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import EmptyState from '../../Components/EmptyState.vue';
import AppLayout from '../../Layouts/AppLayout.vue';

type Player = { id: number; name: string; streams_count: number; created_at: string };

const props = defineProps<{ players: Player[] }>();
const page = usePage<{ flash?: { success?: string; error?: string } }>();
const showForm = ref(false);
const editingPlayer = ref<Player | null>(null);
const form = useForm({ name: '' });
const successMessage = computed(() => page.props.flash?.success);
const errorMessage = computed(() => page.props.flash?.error);

const formatDate = (value: string) => new Intl.DateTimeFormat('en-GB', {
    day: '2-digit', month: 'short', year: 'numeric',
}).format(new Date(value));

const openCreateForm = () => {
    editingPlayer.value = null;
    form.reset();
    form.clearErrors();
    showForm.value = true;
};

const openEditForm = (player: Player) => {
    editingPlayer.value = player;
    form.name = player.name;
    form.clearErrors();
    showForm.value = true;
};

const closeForm = () => {
    if (!form.processing) showForm.value = false;
};

const submit = () => {
    if (editingPlayer.value) {
        form.put(`/players/${editingPlayer.value.id}`, { onSuccess: () => { showForm.value = false; } });
        return;
    }

    form.post('/players', { onSuccess: () => { showForm.value = false; } });
};

const removePlayer = (player: Player) => {
    if (player.streams_count > 0) {
        window.alert(`Cannot delete ${player.name}. This player has ${player.streams_count} stream${player.streams_count === 1 ? '' : 's'}. Remove or reassign the streams first.`);
        return;
    }

    if (window.confirm(`Delete ${player.name}?`)) router.delete(`/players/${player.id}`);
};
</script>

<template>
    <Head title="Players" />
    <AppLayout title="Players" eyebrow="Analysis">
        <div class="streams-toolbar">
            <div>
                <p class="section-kicker">Analysis</p>
                <h2 class="page-section-title">Your players</h2>
                <p class="muted-copy">Manage the creators connected to your stream library.</p>
            </div>
            <button class="primary-button" type="button" @click="openCreateForm"><span>＋</span> Add Player</button>
        </div>

        <div v-if="successMessage" class="success-banner" role="status">{{ successMessage }}</div>
        <div v-if="errorMessage" class="error-banner" role="alert">{{ errorMessage }}</div>

        <div v-if="props.players.length" class="streams-panel">
            <div class="streams-table-wrap">
                <table class="streams-table players-table">
                    <thead><tr><th>Player</th><th>Streams</th><th>Created</th><th><span class="sr-only">Actions</span></th></tr></thead>
                    <tbody>
                        <tr v-for="player in props.players" :key="player.id">
                            <td><span class="player-cell"><span class="player-avatar">{{ player.name.charAt(0) }}</span><span class="stream-title">{{ player.name }}</span></span></td>
                            <td>{{ player.streams_count }}</td>
                            <td>{{ formatDate(player.created_at) }}</td>
                            <td class="action-cell"><button class="text-action" type="button" @click="openEditForm(player)">Edit</button><button class="delete-button" type="button" @click="removePlayer(player)">Delete</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div v-else class="standalone-panel"><EmptyState title="No players yet" description="Add your first player to start organizing the stream library." /></div>

        <div v-if="showForm" class="modal-backdrop" role="presentation" @click.self="closeForm">
            <section class="stream-modal player-modal" role="dialog" aria-modal="true" aria-labelledby="player-form-title">
                <div class="modal-heading"><div><p class="section-kicker">Analysis</p><h2 id="player-form-title">{{ editingPlayer ? 'Edit player' : 'Add player' }}</h2></div><button class="modal-close" type="button" aria-label="Close" @click="closeForm">×</button></div>
                <form @submit.prevent="submit">
                    <div class="form-field"><label for="player-name">Player name</label><input id="player-name" v-model="form.name" type="text" placeholder="Sophie" autofocus /><p v-if="form.errors.name" class="form-error">{{ form.errors.name }}</p></div>
                    <div class="modal-actions"><button class="secondary-button" type="button" @click="closeForm">Cancel</button><button class="primary-button" type="submit" :disabled="form.processing">{{ form.processing ? 'Saving…' : editingPlayer ? 'Save changes' : 'Add player' }}</button></div>
                </form>
            </section>
        </div>
    </AppLayout>
</template>
