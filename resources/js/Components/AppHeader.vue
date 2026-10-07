<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted } from 'vue';
import { workerTaskLabel, type Worker } from '../types/workers';

defineProps<{ title: string; eyebrow?: string }>();

const page = usePage<{ workers: Worker[] }>();
const usable = computed(() => (page.props.workers ?? []).filter((worker) => worker.online && worker.enabled));
const workerSummary = computed(() => usable.value.length === 0 ? 'Geen worker online' : usable.value.length === 1 ? '1 worker online' : usable.value.length + ' workers online');
const workerTooltip = computed(() => usable.value.map((worker) => worker.name + (worker.current_task ? ': ' + workerTaskLabel(worker.current_task) : ': vrij')).join('\n') || 'Start een worker op je laptop, pc of Mac (make remote-worker).');

// Workers come and go (a laptop is closed); refresh just this prop now and then.
let timer: number | undefined;
onMounted(() => { timer = window.setInterval(() => router.reload({ only: ['workers'] }), 15000); });
onBeforeUnmount(() => window.clearInterval(timer));
</script>
<template>
    <header class="app-header">
        <div><p v-if="eyebrow" class="header-eyebrow">{{ eyebrow }}</p><h1>{{ title }}</h1></div>
        <div class="header-actions">
            <Link href="/settings" class="worker-pill" :class="{ 'worker-pill-offline': usable.length === 0 }" :title="workerTooltip"><span class="status-dot" />{{ workerSummary }}</Link>
            <button class="icon-button" aria-label="Meldingen"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M15 8a5 5 0 0 0-10 0c0 5-2 5-2 6h14c0-1-2-1-2-6ZM8 17h4" /></svg></button>
            <div class="profile-chip"><span class="profile-avatar">A</span><span class="profile-name">Admin</span><span class="profile-chevron">⌄</span></div>
        </div>
    </header>
</template>
