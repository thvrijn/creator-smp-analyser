<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import type { PlayerOption } from '../types/streams';

const props = defineProps<{ players: PlayerOption[]; defaultPlayerId?: number }>();
const open = defineModel<boolean>('open', { required: true });
const initialPlayerId = () => props.defaultPlayerId ?? props.players[0]?.id ?? '';
const form = useForm<{ player_id: number | ''; title: string; started_at: string; ended_at: string; source: string; video: File | null }>({ player_id: initialPlayerId(), title: '', started_at: '', ended_at: '', source: '', video: null });
watch(open, (value) => { if (value) { form.clearErrors(); form.player_id = form.player_id || initialPlayerId(); } });
const close = () => { if (!form.processing) open.value = false; };
const submit = () => form.post('/streams', { forceFormData: true, preserveScroll: true, onSuccess: () => { form.reset(); form.player_id = initialPlayerId(); open.value = false; } });
const selectVideo = (event: Event) => { form.video = (event.target as HTMLInputElement).files?.[0] ?? null; };
</script>

<template>
    <div v-if="open" class="modal-backdrop" role="presentation" @click.self="close"><section class="stream-modal" role="dialog" aria-modal="true" aria-labelledby="add-stream-title">
        <div class="modal-heading"><div><p class="section-kicker">Library</p><h2 id="add-stream-title">Add stream</h2></div><button class="modal-close" type="button" aria-label="Close" @click="close">×</button></div>
        <form @submit.prevent="submit" enctype="multipart/form-data">
            <div class="form-field"><label for="player">Player</label><select id="player" v-model="form.player_id"><option v-for="player in players" :key="player.id" :value="player.id">{{ player.name }}</option></select><p v-if="form.errors.player_id" class="form-error">{{ form.errors.player_id }}</p></div>
            <div class="form-field"><label for="title">Title</label><input id="title" v-model="form.title" type="text" placeholder="Evening SMP" autofocus /><p v-if="form.errors.title" class="form-error">{{ form.errors.title }}</p></div>
            <div class="form-grid"><div class="form-field"><label for="started_at">Started at</label><input id="started_at" v-model="form.started_at" type="datetime-local" /><p v-if="form.errors.started_at" class="form-error">{{ form.errors.started_at }}</p></div><div class="form-field"><label for="ended_at">Ended at <span>(optional)</span></label><input id="ended_at" v-model="form.ended_at" type="datetime-local" /><p v-if="form.errors.ended_at" class="form-error">{{ form.errors.ended_at }}</p></div></div>
            <div class="form-field"><label for="source">Source <span>(optional)</span></label><input id="source" v-model="form.source" type="text" placeholder="youtube, twitch, local or URL" /><p v-if="form.errors.source" class="form-error">{{ form.errors.source }}</p></div>
            <div class="form-field"><label for="video">Video <span>(optional)</span></label><input id="video" type="file" accept=".mp4,.mkv,.webm,.mov,video/mp4,video/x-matroska,video/webm,video/quicktime" @change="selectVideo" /><p class="field-hint">{{ form.video?.name || 'Attach the original stream recording.' }}</p><p v-if="form.errors.video" class="form-error">{{ form.errors.video }}</p></div>
            <div class="modal-actions"><button class="secondary-button" type="button" @click="close">Cancel</button><button class="primary-button" type="submit" :disabled="form.processing">{{ form.processing ? 'Saving…' : 'Save stream' }}</button></div>
        </form>
    </section></div>
</template>
