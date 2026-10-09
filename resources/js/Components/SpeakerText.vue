<script setup lang="ts">
import { computed } from 'vue';
import { SPEAKER_MENTION, type Speaker } from '../composables/speakers';

// A generated text (event title or description, storyline) in which "Spreker 6" is clickable: it shows that speaker's
// current name (named or recognised after the analysis) and opens the speaker editor to say who it is.
const props = defineProps<{ text: string; speakers: Speaker[] }>();
const emit = defineEmits<{ pick: [speaker: number] }>();

type Piece = { text: string; speaker?: Speaker };
const pieces = computed<Piece[]>(() => {
    const result: Piece[] = [];
    let last = 0;
    for (const match of props.text.matchAll(SPEAKER_MENTION)) {
        const speaker = props.speakers.find((item) => item.speaker === Number(match[1]));
        if (!speaker) continue;
        result.push({ text: props.text.slice(last, match.index) }, { text: match[0], speaker });
        last = (match.index ?? 0) + match[0].length;
    }
    result.push({ text: props.text.slice(last) });
    return result;
});
const label = (piece: Piece) => piece.speaker!.source === 'default' || piece.speaker!.source === 'unknown' ? piece.text : piece.speaker!.name;
const title = (piece: Piece) => piece.text + (piece.speaker!.source === 'default' || piece.speaker!.source === 'unknown' ? ': nog onbekend' : ' = ' + piece.speaker!.name + (piece.speaker!.source === 'matched' ? ' (herkend)' : '')) + '. Klik om te zeggen wie dit is.';
const pick = (piece: Piece) => emit('pick', piece.speaker!.speaker);
</script>
<template>
    <!-- A span, not a button: it can sit inside the event's own button. -->
    <template v-for="(piece, index) in pieces" :key="index"><span v-if="piece.speaker" class="speaker-mention" :class="{ 'speaker-mention-known': piece.speaker.source === 'named' || piece.speaker.source === 'matched' }" role="button" tabindex="0" :title="title(piece)" @click.stop="pick(piece)" @keydown.enter.stop.prevent="pick(piece)">{{ label(piece) }}<template v-if="piece.speaker.source === 'matched'">?</template></span><template v-else>{{ piece.text }}</template></template>
</template>
