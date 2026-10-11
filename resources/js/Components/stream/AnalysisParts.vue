<script setup lang="ts">
import { computed } from 'vue';
import SpeakerText from '../SpeakerText.vue';
import { partStatus, partStatusLabels } from '../../composables/streamAnalysis';
import { formatSeconds } from '../../composables/streamStatus';
import type { Speaker } from '../../composables/speakers';
import type { StoryPart, StreamDetail, StreamEvent } from '../../types/streamDetail';

// The analysis' own parts (ExtractStreamEventsJob::chunks: 5 minutes, overlapping), to check what the model saw and
// wrote per part and where a running analysis is. A summary belongs to the part with the same first line.
const props = defineProps<{ parts: { start_time: number; end_time: number }[]; stream: StreamDetail; events: StreamEvent[]; speakers: Speaker[]; canPlay: boolean }>();
const emit = defineEmits<{ play: [seconds: number]; pick: [speaker: number, at: number] }>();

const rows = computed(() => props.parts.map((part, index) => ({
    ...part,
    index,
    summary: props.stream.story_parts.find((story: StoryPart) => Math.abs(story.start_time - part.start_time) < 0.5)?.summary ?? null,
    events: props.events.filter((event) => event.start_time >= part.start_time && event.start_time < part.end_time).length,
    status: partStatus(props.stream, index),
})));
</script>

<template>
    <section class="analysis-card">
        <div class="analysis-card-head"><h3>Analysedetails</h3><span class="muted-inline">{{ parts.length }} stukken van 5 minuten, 1 minuut overlap</span></div>
        <div class="data-table-wrap">
            <table class="data-table parts-table">
                <thead><tr><th class="play-cell"><span class="sr-only">Afspelen</span></th><th>#</th><th>Begin</th><th>Einde</th><th>Wat het model schreef</th><th>Events</th><th>Status</th></tr></thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.index">
                        <td class="play-cell"><button v-if="canPlay" class="round-play" type="button" title="Afspelen vanaf hier" aria-label="Afspelen vanaf hier" @click="emit('play', row.start_time)"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M7 5.2v9.6a.7.7 0 0 0 1 .6l7.4-4.8a.7.7 0 0 0 0-1.2L8 4.6a.7.7 0 0 0-1 .6Z" /></svg></button></td>
                        <td class="time-cell muted-inline">{{ row.index + 1 }}</td>
                        <td class="time-cell">{{ formatSeconds(row.start_time) }}</td>
                        <td class="time-cell">{{ formatSeconds(row.end_time) }}</td>
                        <td class="summary-cell"><SpeakerText v-if="row.summary" :text="row.summary" :speakers="speakers" @pick="emit('pick', $event, row.start_time)" /><span v-else class="muted-inline">{{ row.status === 'done' ? 'Niets in de game' : '—' }}</span></td>
                        <td class="time-cell">{{ row.events || '' }}</td>
                        <td><span class="status-chip" :class="'status-' + row.status">{{ partStatusLabels[row.status] }}</span></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
