<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import PlayerAvatar from './PlayerAvatar.vue';
import { canCancel, cancelTask, downloadLabel, eventExtractionLabel, eventProgressDetails, formatSeconds, isCancelling, stageLabel, stalledMessage, transcriptionLabel, type CancellableTask } from '../composables/streamStatus';
import type { Stream } from '../types/streams';

// The streams with a download, transcription or analysis in the queue, waiting for a worker or running (dashboard).
defineProps<{ streams: Stream[] }>();

type Job = { task: CancellableTask; label: string; status: string; badge: string; progress: number | null; details: string[]; stalled: boolean };
const active = ['queued', 'waiting', 'processing'];
const badge = (status: string) => 'transcription-' + (status === 'processing' ? 'processing' : 'queued');
const clamp = (value: number) => Math.max(0, Math.min(100, value));

const jobs = (stream: Stream): Job[] => {
    const list: Job[] = [];
    if (active.includes(stream.video_download_status)) {
        list.push({
            task: 'video_download', label: 'Audio ophalen', status: stream.video_download_status === 'processing' ? 'Bezig' : 'In wachtrij', badge: badge(stream.video_download_status),
            progress: stream.video_download_status === 'processing' ? clamp(stream.video_download_progress) : null,
            details: stream.video_download_status === 'processing' ? [downloadLabel(stream)] : [], stalled: stream.video_download_stalled,
        });
    }
    if (active.includes(stream.transcription_status)) {
        const running = stream.transcription_status === 'processing';
        list.push({
            task: 'transcription', label: 'Transcriberen', status: transcriptionLabel(stream.transcription_status), badge: badge(stream.transcription_status),
            progress: running ? clamp(stream.transcription_progress) : null,
            details: [
                stageLabel(stream.transcription_stage),
                ...(running && stream.transcription_duration_seconds ? [formatSeconds(stream.transcription_processed_seconds) + ' / ' + formatSeconds(stream.transcription_duration_seconds)] : []),
                ...(running && stream.transcription_eta_seconds !== null ? ['nog ~ ' + formatSeconds(stream.transcription_eta_seconds)] : []),
            ],
            stalled: stream.transcription_stalled,
        });
    }
    if (active.includes(stream.event_extraction_status)) {
        const running = stream.event_extraction_status === 'processing';
        list.push({
            task: 'event_extraction', label: 'Analyseren', status: eventExtractionLabel(stream.event_extraction_status), badge: badge(stream.event_extraction_status),
            progress: running && stream.event_extraction_progress !== null ? stream.event_extraction_progress : null,
            details: running ? [eventProgressDetails(stream)].filter(Boolean) : [], stalled: stream.event_extraction_stalled,
        });
    }
    return list;
};
</script>

<template>
    <section class="active-jobs" aria-label="Nu bezig">
        <h3 class="section-kicker player-group-title">Nu bezig <span>{{ streams.length }}</span></h3>
        <div class="active-jobs-list">
            <article v-for="stream in streams" :key="stream.id" class="active-job">
                <Link class="active-job-stream" :href="'/streams/' + stream.id">
                    <PlayerAvatar :name="stream.player.name" :photo-url="stream.player.photo_url" size="sm" />
                    <span><strong>{{ stream.player.name }}</strong><span>{{ stream.title }}</span></span>
                </Link>
                <div v-for="job in jobs(stream)" :key="job.task" class="active-job-task">
                    <div class="active-job-head">
                        <span class="active-job-label">{{ job.label }}</span>
                        <span class="transcription-badge" :class="job.badge">{{ job.status }}</span>
                        <span v-if="job.progress !== null" class="active-job-percent">{{ Math.round(job.progress) }}%</span>
                        <button v-if="canCancel(stream, job.task)" class="cancel-link" type="button" @click="cancelTask(stream, job.task)">✕ Annuleren</button>
                        <span v-else-if="isCancelling(stream, job.task)" class="transcription-stage">Wordt geannuleerd…</span>
                    </div>
                    <div v-if="job.progress !== null" class="progress-track active-job-progress"><span :style="{ width: job.progress + '%' }" /></div>
                    <div v-if="job.details.length || stream.worker_name" class="transcription-meta"><span v-for="detail in job.details" :key="detail">{{ detail }}</span><span v-if="stream.worker_name && job.task !== 'video_download'">worker {{ stream.worker_name }}</span></div>
                    <p v-if="job.stalled" class="stream-error">{{ stalledMessage }}</p>
                </div>
            </article>
        </div>
    </section>
</template>
