<script setup lang="ts">
import { computed } from 'vue';
import { categories, categoryLabels, type Moment } from '../../composables/streamAnalysis';
import { formatSeconds } from '../../composables/streamStatus';

// The whole stream on one line: events as coloured blocks at their real time and length (overlapping events in
// lanes), quiet stretches as the grey track and silences hatched. A click selects a moment; the playhead shows the audio.
const props = defineProps<{ moments: Moment[]; selected: string | null; playhead: number | null }>();
const emit = defineEmits<{ select: [key: string] }>();

const length = computed(() => Math.max(0, ...props.moments.map((moment) => moment.end)));
const position = (seconds: number) => (length.value ? (seconds / length.value) * 100 : 0) + '%';
const width = (moment: Moment) => (length.value ? ((moment.end - moment.start) / length.value) * 100 : 0) + '%';

// Each event in the first lane where it does not overlap the previous one.
const lanes = computed(() => {
    const ends: number[] = [];
    const lane = new Map<string, number>();
    for (const moment of props.moments.filter((item) => item.kind === 'event')) {
        let index = ends.findIndex((end) => end <= moment.start);
        if (index === -1) { index = ends.length; ends.push(0); }
        ends[index] = moment.end;
        lane.set(moment.key, index);
    }
    return { lane, count: Math.max(1, ends.length) };
});
const events = computed(() => props.moments.filter((moment) => moment.kind === 'event'));
const backgrounds = computed(() => props.moments.filter((moment) => moment.kind !== 'event'));
const present = computed(() => categories.filter((category) => events.value.some((moment) => moment.category === category)));

// A tick per hour (per half hour or 15 minutes for a shorter stream), and always the end.
const ticks = computed(() => {
    if (!length.value) return [];
    const step = length.value > 3 * 3600 ? 3600 : length.value > 3600 ? 1800 : 900;
    const list = [];
    for (let at = 0; at < length.value - step * 0.4; at += step) list.push(at);
    return [...list, length.value];
});
const tickLabel = (seconds: number) => formatSeconds(seconds).replace(/^0/, '');
const title = (moment: Moment) => formatSeconds(moment.start) + ' – ' + formatSeconds(moment.end) + ': '
    + (moment.event?.title ?? (moment.kind === 'silent' ? 'geen spraak' : moment.summaries[0] ?? 'rustig'));
</script>

<template>
    <section class="analysis-card">
        <div class="analysis-card-head"><h3>Tijdlijn van de stream</h3><span class="muted-inline">{{ events.length }} {{ events.length === 1 ? 'event' : 'events' }}</span></div>
        <div class="moment-track" :style="{ '--lanes': lanes.count }">
            <button v-for="moment in backgrounds" :key="moment.key" type="button" class="moment-gap" :class="['moment-gap-' + moment.kind, { 'moment-selected': moment.key === selected }]"
                :style="{ left: position(moment.start), width: width(moment) }" :title="title(moment)" :aria-label="title(moment)" @click="emit('select', moment.key)" />
            <button v-for="moment in events" :key="moment.key" type="button" class="moment-block" :class="['category-' + moment.category, { 'moment-selected': moment.key === selected }]"
                :style="{ left: position(moment.start), width: width(moment), '--lane': lanes.lane.get(moment.key) }" :title="title(moment)" :aria-label="title(moment)" @click="emit('select', moment.key)" />
            <span v-if="playhead !== null && length" class="timeline-playhead" :style="{ left: position(playhead) }" />
        </div>
        <div class="timeline-ticks"><span v-for="tick in ticks" :key="tick" :style="{ left: position(tick) }">{{ tickLabel(tick) }}</span></div>
        <div class="timeline-legend">
            <span v-for="category in present" :key="category"><i :class="'category-' + category" />{{ categoryLabels[category] }}</span>
            <span><i class="legend-quiet" />Rustig</span>
            <span><i class="legend-silent" />Geen spraak</span>
        </div>
    </section>
</template>
