<script setup lang="ts">
import { computed, ref } from 'vue';
import DemoBadge from '../DemoBadge.vue';
import SpeakerText from '../SpeakerText.vue';
import { categoryShort, durationLabel, relevanceLevel, type Moment } from '../../composables/streamAnalysis';
import { eventTypeLabel, formatSeconds } from '../../composables/streamStatus';
import type { Speaker } from '../../composables/speakers';

// "Wat gebeurde er": the events in order with the quiet stretches between them, folded to one line each (a click
// opens what the analysis wrote about that time). A row selects the moment; ▶, Transcript and Clip act on it.
// analysed: whether the analysis ran; before that a stretch with speech is not "quiet", just not looked at yet.
const props = defineProps<{ moments: Moment[]; selected: string | null; analysed: boolean; canPlay: boolean; speakers: Speaker[]; playersOf: (moment: Moment) => string[]; hasClip: (moment: Moment) => boolean }>();
const emit = defineEmits<{ select: [key: string]; play: [moment: Moment]; transcript: [moment: Moment]; clip: [moment: Moment]; pick: [speaker: number, at: number] }>();

const onlyEvents = ref(false);
const open = ref(new Set<string>());
const toggle = (key: string) => {
    const next = new Set(open.value);
    if (next.has(key)) next.delete(key); else next.add(key);
    open.value = next;
};
const visible = computed(() => props.moments.filter((moment) => !onlyEvents.value || moment.kind === 'event'));
const eventCount = computed(() => props.moments.filter((moment) => moment.kind === 'event').length);
const select = (moment: Moment) => {
    emit('select', moment.key);
    if (moment.kind === 'quiet' && moment.summaries.length) toggle(moment.key);
};
</script>

<template>
    <section class="analysis-card">
        <div class="analysis-card-head">
            <h3>Wat gebeurde er</h3>
            <label class="check-toggle"><input v-model="onlyEvents" type="checkbox" />Alleen events</label>
        </div>
        <p v-if="!analysed && moments.length > 1" class="context-note moment-unanalysed">Nog niet geanalyseerd: hieronder staat alleen waar er gesproken wordt. Analyseer de stream om de verhaalmomenten te vinden.</p>
        <ol v-if="eventCount || moments.length > 1" class="moment-list">
            <li v-for="moment in visible" :key="moment.key" class="moment-row" :class="['moment-row-' + moment.kind, { 'moment-row-selected': moment.key === selected }]">
                <span class="moment-rail" :class="moment.category ? 'category-' + moment.category : 'moment-rail-' + moment.kind" aria-hidden="true" />
                <button type="button" class="moment-main" :aria-expanded="moment.kind === 'quiet' && moment.summaries.length ? open.has(moment.key) : undefined" @click="select(moment)">
                    <span class="moment-time">{{ formatSeconds(moment.start) }}<small>{{ durationLabel(moment.end - moment.start) }}</small></span>
                    <span v-if="moment.event" class="moment-body">
                        <span class="moment-head"><span class="event-type" :class="'event-type-' + moment.event.type">{{ moment.event.type === 'other' ? 'Overig' : eventTypeLabel(moment.event.type) }}</span><strong><SpeakerText :text="moment.event.title" :speakers="speakers" @pick="emit('pick', $event, moment.start)" /></strong></span>
                        <span class="moment-description"><SpeakerText :text="moment.event.description" :speakers="speakers" @pick="emit('pick', $event, moment.start)" /></span>
                        <span class="moment-meta">{{ playersOf(moment).join(', ') }}<span class="meta-dot">•</span>{{ Math.round(moment.event.confidence * 100) }}% zeker<span class="meta-dot">•</span><span class="tag" :class="'tag-' + moment.category">{{ categoryShort[moment.category!] }}</span></span>
                    </span>
                    <span v-else class="moment-body">
                        <span class="moment-head"><strong class="moment-quiet-title">{{ moment.kind === 'silent' ? 'Geen spraak' : analysed ? 'Rustig' : 'Spraak' }}</strong><span class="muted-inline">{{ moment.kind === 'silent' ? 'niet getranscribeerd of stil' : !analysed ? 'nog niet geanalyseerd' : moment.summaries.length ? (open.has(moment.key) ? '' : moment.summaries.length === 1 ? moment.summaries[0] : moment.summaries[0] + ' (+' + (moment.summaries.length - 1) + ')') : 'gameplay en chat, geen verhaalmomenten' }}</span></span>
                        <ul v-if="open.has(moment.key)" class="moment-summaries"><li v-for="(summary, index) in moment.summaries" :key="index"><SpeakerText :text="summary" :speakers="speakers" @pick="emit('pick', $event, moment.start)" /></li></ul>
                    </span>
                </button>
                <span v-if="moment.relevance !== null" class="relevance moment-relevance" title="Relevantie voor het verhaal (voorbeeld)"><span class="relevance-value">{{ moment.relevance }}</span><span class="relevance-bar" :class="'relevance-' + relevanceLevel(moment.relevance)"><span :style="{ width: moment.relevance + '%' }" /></span></span>
                <span v-if="moment.kind !== 'silent'" class="moment-actions">
                    <button v-if="canPlay" class="round-play" type="button" title="Afspelen vanaf hier" aria-label="Afspelen vanaf hier" @click="emit('play', moment)"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M7 5.2v9.6a.7.7 0 0 0 1 .6l7.4-4.8a.7.7 0 0 0 0-1.2L8 4.6a.7.7 0 0 0-1 .6Z" /></svg></button>
                    <button class="event-clip-button" type="button" @click="emit('transcript', moment)">Transcript</button>
                    <button v-if="moment.event" class="event-clip-button" :class="{ 'event-clip-done': hasClip(moment) }" type="button" @click="emit('clip', moment)">{{ hasClip(moment) ? '✓ Clip' : '+ Clip' }}</button>
                </span>
            </li>
        </ol>
        <p v-else class="muted-copy analysis-empty">Nog geen transcript of events. Transcribeer en analyseer de stream eerst.</p>
        <p v-if="eventCount" class="context-note">Relevantie per event <DemoBadge title="Relevantie wordt nog niet door de analyse bepaald: afgeleid uit het type, de zekerheid en de lengte." /></p>
    </section>
</template>
