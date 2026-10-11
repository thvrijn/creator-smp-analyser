<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import DemoBadge from '../Components/DemoBadge.vue';
import AppLayout from '../Layouts/AppLayout.vue';

// Not built yet: recap episodes put together from clips (the video builder) per day or storyline.
const episodes = [
    { title: 'Aflevering 1 – De eerste nacht', length: '18:40', clips: 23, status: 'Klaar om te monteren' },
    { title: 'Aflevering 2 – Onderhandelingen', length: '14:05', clips: 17, status: 'Clips kiezen' },
    { title: 'Aflevering 3 – Wantrouwen', length: '—', clips: 4, status: 'Concept' },
];
</script>
<template>
    <Head title="Afleveringen" />
    <AppLayout title="Afleveringen" eyebrow="Media">
        <p class="muted-copy page-intro">Een aflevering is een recap van een dag of verhaallijn, gemaakt van clips uit de <Link class="inline-link" href="/video-builder">videobouwer</Link>. <DemoBadge title="Afleveringen zijn nog niet gebouwd: dit zijn voorbeelden." /></p>
        <div class="analysis-card">
            <table class="data-table">
                <thead><tr><th>Aflevering</th><th>Lengte</th><th>Clips</th><th>Status</th></tr></thead>
                <tbody><tr v-for="episode in episodes" :key="episode.title"><td>{{ episode.title }}</td><td class="time-cell">{{ episode.length }}</td><td>{{ episode.clips }}</td><td><span class="status-chip status-queued">{{ episode.status }}</span></td></tr></tbody>
            </table>
        </div>
    </AppLayout>
</template>
