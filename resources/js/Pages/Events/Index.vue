<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import EmptyState from '../../Components/EmptyState.vue';
import AppLayout from '../../Layouts/AppLayout.vue';

type EventItem = { id: number; stream: { id: number; title: string }; type: string; title: string; description: string; start_time: number; end_time: number; confidence: number; segment_count: number };
defineProps<{ events: EventItem[] }>();
const formatSeconds = (value: number) => { const total = Math.max(0, Math.round(value)); return [Math.floor(total / 3600), Math.floor((total % 3600) / 60), total % 60].map((part) => String(part).padStart(2, '0')).join(':'); };
</script>
<template><Head title="Events" /><AppLayout title="Events" eyebrow="Analysis"><div v-if="events.length" class="events-list"><Link v-for="event in events" :key="event.id" class="standalone-panel event-link" :href="'/streams/' + event.stream.id + '?event=' + event.id"><div class="panel-heading"><div><p class="section-kicker">{{ event.type }} · {{ formatSeconds(event.start_time) }}</p><h3>{{ event.title }}</h3></div><span class="panel-badge">{{ Math.round(event.confidence * 100) }}%</span></div><p class="muted-copy">{{ event.description }}</p><p class="stream-source">{{ event.stream.title }} · {{ event.segment_count }} transcriptsegmenten</p></Link></div><div v-else class="standalone-panel"><EmptyState title="No events yet" description="Detected events will appear here after analysis." /></div></AppLayout></template>
