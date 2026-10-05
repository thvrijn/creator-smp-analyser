<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import PlayerAvatar from './PlayerAvatar.vue';
import type { EditablePlayer } from '../types/streams';

// Without a player the form adds one; with a player it edits that player.
const props = defineProps<{ player: EditablePlayer | null }>();
const open = defineModel<boolean>('open', { required: true });
const form = useForm<{ name: string; twitch_login: string; photo: File | null; remove_photo: boolean }>({ name: '', twitch_login: '', photo: null, remove_photo: false });
const photoInput = ref<HTMLInputElement | null>(null);
const photoPreview = ref<string | null>(null);
// A newly chosen photo wins; otherwise the current one, unless it is being removed.
const shownPhoto = computed(() => photoPreview.value ?? (form.remove_photo ? null : props.player?.photo_url ?? null));

watch(open, (value) => {
    if (!value) return;
    form.reset();
    form.clearErrors();
    form.name = props.player?.name ?? '';
    form.twitch_login = props.player?.twitch_login ?? '';
    photoPreview.value = null;
});

const close = () => { if (!form.processing) open.value = false; };

const selectPhoto = (event: Event) => {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    form.photo = file;
    form.remove_photo = false;
    photoPreview.value = file ? URL.createObjectURL(file) : null;
};

const removePhoto = () => {
    form.photo = null;
    form.remove_photo = props.player?.photo_url != null;
    photoPreview.value = null;
    if (photoInput.value) photoInput.value.value = '';
};

// A pasted channel URL (https://www.twitch.tv/name) becomes just the name.
const twitchLogin = (value: string) => value.trim().replace(/[?#].*$/, '').replace(/\/+$/, '').split('/').pop()!.replace(/^@/, '').toLowerCase();

// Files need multipart, and PHP does not parse multipart PUT, so an edit is a POST with _method=put.
const submit = () => {
    const player = props.player;
    form.transform((data) => ({ ...data, twitch_login: twitchLogin(data.twitch_login), ...(player ? { _method: 'put' } : {}) }))
        .post(player ? `/players/${player.id}` : '/players', { forceFormData: true, preserveScroll: true, onSuccess: () => { open.value = false; } });
};
</script>

<template>
    <div v-if="open" class="modal-backdrop" role="presentation" @click.self="close">
        <section class="stream-modal player-modal" role="dialog" aria-modal="true" aria-labelledby="player-form-title">
            <div class="modal-heading"><div><p class="section-kicker">Analyse</p><h2 id="player-form-title">{{ player ? 'Speler bewerken' : 'Speler toevoegen' }}</h2></div><button class="modal-close" type="button" aria-label="Sluiten" @click="close">×</button></div>
            <form @submit.prevent="submit">
                <div class="form-field"><label for="player-name">Naam speler</label><input id="player-name" v-model="form.name" type="text" placeholder="Sophie" autofocus /><p v-if="form.errors.name" class="form-error">{{ form.errors.name }}</p></div>
                <div class="form-field"><label for="player-twitch">Twitch-kanaal <span>(optioneel)</span></label><input id="player-twitch" v-model="form.twitch_login" type="text" placeholder="kanaalnaam of twitch.tv-link" /><p v-if="form.errors.twitch_login" class="form-error">{{ form.errors.twitch_login }}</p></div>
                <div class="form-field">
                    <label for="player-photo">Foto <span>(optioneel)</span></label>
                    <div class="photo-field">
                        <PlayerAvatar :name="form.name" :photo-url="shownPhoto" size="lg" />
                        <div class="photo-field-input">
                            <input id="player-photo" ref="photoInput" type="file" accept="image/jpeg,image/png,image/webp" @change="selectPhoto" />
                            <p class="field-hint">JPG, PNG of WebP, max. 2 MB.<button v-if="shownPhoto" class="text-action" type="button" @click="removePhoto">Foto verwijderen</button></p>
                        </div>
                    </div>
                    <p v-if="form.errors.photo" class="form-error">{{ form.errors.photo }}</p>
                </div>
                <div class="modal-actions"><button class="secondary-button" type="button" @click="close">Annuleren</button><button class="primary-button" type="submit" :disabled="form.processing">{{ form.processing ? 'Opslaan…' : player ? 'Wijzigingen opslaan' : 'Speler toevoegen' }}</button></div>
            </form>
        </section>
    </div>
</template>
