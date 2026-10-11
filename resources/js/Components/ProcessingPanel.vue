<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, watch } from 'vue';
import type { Stream } from '../types/streams';

// The "Bezig" block at the bottom of the sidebar: the pipeline of the stream that is being processed now (the shared
// prop active_jobs, running first). Linking events and updating the context are not separate steps yet: they show as
// done when the analysis is done.
const page = usePage<{ active_jobs: Stream[] }>();
const jobs = computed(() => page.props.active_jobs ?? []);
const current = computed(() => jobs.value[0] ?? null);

type StepState = 'done' | 'active' | 'waiting' | 'todo';
type Step = { label: string; state: StepState; demo?: boolean };
const waiting = ['queued', 'waiting'];
const steps = computed<Step[]>(() => {
    const stream = current.value;
    if (!stream) return [];
    const transcribed = stream.transcription_status === 'completed';
    const diarizing = stream.transcription_status === 'processing' && stream.transcription_stage === 'diarizing';
    const analysed = stream.event_extraction_status === 'completed';
    const state = (done: boolean, active: boolean, queued: boolean): StepState => done ? 'done' : active ? 'active' : queued ? 'waiting' : 'todo';
    return [
        ...(stream.twitch_video_id ? [{ label: 'Audio ophalen', state: state(Boolean(stream.video_path), stream.video_download_status === 'processing', waiting.includes(stream.video_download_status)) }] : []),
        { label: 'Transcriberen', state: state(transcribed || diarizing, stream.transcription_status === 'processing', waiting.includes(stream.transcription_status)) },
        { label: 'Sprekers herkennen', state: state(transcribed, diarizing, false) },
        { label: 'Analyseren', state: state(analysed, stream.event_extraction_status === 'processing', waiting.includes(stream.event_extraction_status)) },
        { label: 'Events koppelen', state: state(analysed, false, false), demo: true },
        { label: 'Context bijwerken', state: state(analysed, false, false), demo: true },
    ];
});
// The whole pipeline in one number: audio 10%, transcription 50%, analysis 40%.
const percent = computed(() => {
    const stream = current.value;
    if (!stream) return 0;
    const download = stream.video_path || !stream.twitch_video_id ? 1 : stream.video_download_progress / 100;
    const transcription = stream.transcription_status === 'completed' ? 1 : stream.transcription_status === 'processing' ? stream.transcription_progress / 100 : 0;
    const analysis = stream.event_extraction_status === 'completed' ? 1 : (stream.event_extraction_progress ?? 0) / 100;
    return Math.round((download * 0.1 + transcription * 0.5 + analysis * 0.4) * 100);
});

// Poll this prop: often while something runs, now and then otherwise (a job started from another page or device).
let timer: number | undefined;
const schedule = () => {
    window.clearTimeout(timer);
    timer = window.setTimeout(() => router.reload({ only: ['active_jobs'], onFinish: schedule }), jobs.value.length ? 4000 : 20000);
};
onMounted(schedule);
watch(() => jobs.value.length, schedule);
onBeforeUnmount(() => window.clearTimeout(timer));
</script>

<template>
    <section class="processing-panel" aria-label="Bezig">
        <p class="processing-title">Bezig<span v-if="jobs.length > 1" class="processing-more" :title="jobs.length - 1 + ' andere stream(s) in de wachtrij of bezig'">+{{ jobs.length - 1 }}</span></p>
        <template v-if="current">
            <Link class="processing-stream" :href="'/streams/' + current.id" :title="current.title">{{ current.player.name }} · {{ current.title }}</Link>
            <ul class="processing-steps">
                <li v-for="step in steps" :key="step.label" :class="'processing-' + step.state">
                    <span class="processing-icon" aria-hidden="true"><svg v-if="step.state === 'done'" viewBox="0 0 12 12"><path d="m3 6.2 2 2L9 4" /></svg></span>
                    <span class="processing-label" :class="{ 'processing-demo': step.demo }" :title="step.demo ? 'Voorbeeld: nog geen aparte stap, telt als klaar wanneer de analyse klaar is.' : undefined">{{ step.label }}{{ step.state === 'active' ? '…' : '' }}</span>
                </li>
            </ul>
            <div class="processing-progress" title="Hele verwerking: audio, transcriptie en analyse samen"><span class="progress-track"><span :style="{ width: percent + '%' }" /></span><span>{{ percent }}%</span></div>
        </template>
        <p v-else class="processing-idle">Niets bezig</p>
    </section>
</template>
