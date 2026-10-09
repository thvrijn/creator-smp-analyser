<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, reactive, watch } from 'vue';
import { matchReason, type Speaker } from '../composables/speakers';

// Names a speaker (a player, a free name or unknown), confirms a recognised one, or merges it into another speaker.
// Saved on the server (SpeakerController); used inline in the Transcript tab and as a pop-up from events and the story.
const props = defineProps<{ streamUrl: string; speaker: Speaker; speakers: Speaker[]; players: { id: number; name: string }[]; streamerName: string }>();
const emit = defineEmits<{ close: [] }>();

const form = reactive({ choice: '', label: '', mergeInto: '' });
watch(() => props.speaker.speaker, () => {
    const item = props.speaker;
    form.choice = item.source === 'unknown' ? 'unknown' : item.source !== 'named' ? '' : item.player_id !== null ? String(item.player_id) : 'label';
    form.label = item.label ?? '';
    form.mergeInto = '';
}, { immediate: true });

const options = { preserveScroll: true, preserveState: true, only: ['speakers', 'segments', 'flash', 'errors'], onSuccess: () => emit('close') };
const save = () => {
    const body = form.choice === 'label' ? { label: form.label } : form.choice === 'unknown' ? { unknown: true } : { player_id: form.choice === '' ? null : Number(form.choice) };
    router.put(props.streamUrl + '/speakers/' + props.speaker.speaker, body, options);
};
const automaticLabel = computed(() => {
    const item = props.speaker;
    if (item.source === 'matched') return 'Automatisch: herkend als ' + item.name + ' (' + matchReason(item) + ')';
    return item.speaker === 0 ? 'Automatisch: de streamer (' + props.streamerName + ')' : 'Automatisch: herkennen aan de stem';
});
// A voice match is a guess until confirmed; confirming makes it a known voice of that player.
const confirmMatch = () => {
    if (props.speaker.player_id === null) return;
    router.put(props.streamUrl + '/speakers/' + props.speaker.speaker, { player_id: props.speaker.player_id }, options);
};
const merge = () => {
    if (form.mergeInto === '') return;
    const into = props.speakers.find((item) => item.speaker === Number(form.mergeInto))?.name ?? 'Spreker ' + form.mergeInto;
    if (!window.confirm('Alles van "' + props.speaker.name + '" bij "' + into + '" zetten? Dat kan niet ongedaan worden gemaakt.')) return;
    router.post(props.streamUrl + '/speakers/' + props.speaker.speaker + '/merge', { into: Number(form.mergeInto) }, options);
};
</script>
<template>
    <form class="speaker-editor" @submit.prevent="save">
        <div class="form-field"><label :for="'speaker-name-' + speaker.speaker">Wie is spreker {{ speaker.speaker }}?</label>
            <select :id="'speaker-name-' + speaker.speaker" v-model="form.choice">
                <option value="">{{ automaticLabel }}</option>
                <option value="unknown">Onbekend (niet herkennen)</option>
                <option value="label">Andere naam…</option>
                <option v-for="player in players" :key="player.id" :value="String(player.id)">{{ player.name }}</option>
            </select>
        </div>
        <div v-if="form.choice === 'label'" class="form-field"><label :for="'speaker-label-' + speaker.speaker">Naam</label><input :id="'speaker-label-' + speaker.speaker" v-model="form.label" type="text" maxlength="60" placeholder="bijv. een gast of de chat-TTS" required /></div>
        <button class="primary-button" type="submit">Opslaan</button>
        <button v-if="speaker.source === 'matched'" class="secondary-button" type="button" @click="confirmMatch">✓ Klopt, dit is {{ speaker.name }}</button>
        <Link v-if="speaker.matched_by === 'text' && speaker.text_stream_id" class="inline-link speaker-evidence" :href="'/streams/' + speaker.text_stream_id">Stream van {{ speaker.name }} bekijken ↗</Link>
        <slot />
        <div class="speaker-merge">
            <div class="form-field"><label :for="'speaker-merge-' + speaker.speaker">Is dezelfde persoon als</label>
                <select :id="'speaker-merge-' + speaker.speaker" v-model="form.mergeInto"><option value="">Kies een spreker…</option><option v-for="item in speakers.filter((candidate) => candidate.speaker !== speaker.speaker)" :key="item.speaker" :value="String(item.speaker)">{{ item.name }}</option></select>
            </div>
            <button class="secondary-button" type="button" :disabled="form.mergeInto === ''" @click="merge">Samenvoegen</button>
        </div>
        <button class="modal-close" type="button" aria-label="Sluiten" @click="emit('close')">×</button>
    </form>
</template>
