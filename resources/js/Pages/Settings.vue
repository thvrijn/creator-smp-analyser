<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import EmptyState from '../Components/EmptyState.vue';
import AppLayout from '../Layouts/AppLayout.vue';
import { formatDate } from '../types/streams';
import { workerTaskLabel, type Worker } from '../types/workers';

defineProps<{ onlineSeconds: number }>();

const page = usePage<{ workers: Worker[] }>();
const workers = computed(() => page.props.workers ?? []);
const statusLabel = (worker: Worker) => !worker.enabled ? 'Uitgeschakeld' : worker.online ? (worker.current_task ? 'Bezig' : 'Online') : 'Offline';
const statusClass = (worker: Worker) => 'transcription-' + (!worker.enabled ? 'pending' : worker.online ? (worker.current_task ? 'processing' : 'completed') : 'failed');
const gpuLabel = (worker: Worker) => [worker.gpu_name, worker.vram_mb ? Math.round(worker.vram_mb / 1024) + ' GB' : null, worker.backend?.toUpperCase()].filter(Boolean).join(' · ') || '—';
const capabilityLabel = (worker: Worker) => worker.capabilities.map(workerTaskLabel).join(', ');
const toggle = (worker: Worker) => router.put('/workers/' + worker.id, { enabled: !worker.enabled }, { preserveScroll: true });
const forget = (worker: Worker) => { if (window.confirm('Worker "' + worker.name + '" vergeten?')) router.delete('/workers/' + worker.id, { preserveScroll: true }); };
</script>
<template>
    <Head title="Instellingen" />
    <AppLayout title="Instellingen" eyebrow="Systeem">
        <div class="standalone-panel streams-panel">
            <div class="panel-heading"><div><h3>Workers</h3><p class="muted-copy">Laptops en pc's met een GPU die zich bij de app aanmelden. Een job gaat naar de beste vrije worker; zonder worker wacht hij tot er een online komt. Een worker zonder teken van leven in {{ onlineSeconds }} seconden telt als offline.</p></div></div>
            <EmptyState v-if="workers.length === 0" title="Nog geen workers" description="Start een worker op je laptop, pc of Mac met make remote-worker (zie worker/.env.example). Hij verschijnt hier zodra hij zich aanmeldt." />
            <div v-else class="streams-table-wrap"><table class="streams-table">
                <thead><tr><th>Naam</th><th>Status</th><th>GPU</th><th>Taken</th><th>Laatst gezien</th><th>Nu bezig met</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="worker in workers" :key="worker.id">
                        <td><strong>{{ worker.name }}</strong></td>
                        <td><span class="transcription-badge" :class="statusClass(worker)">{{ statusLabel(worker) }}</span></td>
                        <td>{{ gpuLabel(worker) }}</td>
                        <td>{{ capabilityLabel(worker) }}</td>
                        <td>{{ worker.last_seen_at ? formatDate(worker.last_seen_at) : '—' }}</td>
                        <td><template v-if="worker.current_task">{{ workerTaskLabel(worker.current_task) }}: <a class="inline-link" :href="'/streams/' + worker.current_stream_id">{{ worker.current_stream_title ?? 'stream #' + worker.current_stream_id }}</a></template><template v-else>—</template></td>
                        <td class="action-cell">
                            <button class="secondary-button" type="button" @click="toggle(worker)">{{ worker.enabled ? 'Niet gebruiken' : 'Gebruiken' }}</button>
                            <button v-if="!worker.online && !worker.current_task" class="delete-button" type="button" @click="forget(worker)">Vergeten</button>
                        </td>
                    </tr>
                </tbody>
            </table></div>
        </div>
    </AppLayout>
</template>
