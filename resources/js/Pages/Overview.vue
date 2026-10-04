<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import EmptyState from '../Components/EmptyState.vue';
import StatCard from '../Components/StatCard.vue';
import AppLayout from '../Layouts/AppLayout.vue';
</script>
<template>
    <Head title="Overview" /><AppLayout title="Overview" eyebrow="Workspace">
        <section class="welcome-row"><div><p class="section-kicker">Workspace overview</p><h2>Good morning, Admin</h2><p class="muted-copy">Your CreatorSMP4 workspace is ready for analysis.</p></div><div class="date-pill"><span class="live-dot" />Development workspace</div></section>
        <section class="stats-grid" aria-label="Workspace statistics"><StatCard label="Streams" value="0" icon="play" accent="blue" /><StatCard label="Players" value="0" icon="users" accent="violet" /><StatCard label="Events" value="0" icon="spark" accent="amber" /><StatCard label="Processing" value="Idle" icon="clock" accent="green" /></section>
        <section class="dashboard-grid"><div class="panel"><div class="panel-heading"><div><p class="section-kicker">Library</p><h3>Recent streams</h3></div><span class="panel-link">View all <span>→</span></span></div><EmptyState title="No streams have been added yet" description="Streams will appear here once they are available for analysis." /></div><div class="panel"><div class="panel-heading"><div><p class="section-kicker">Analysis</p><h3>Server timeline</h3></div><span class="panel-badge">Coming soon</span></div><EmptyState icon="timeline" title="No events detected yet" description="Detected events will be organized on the timeline." /></div></section>
    </AppLayout>
</template>
