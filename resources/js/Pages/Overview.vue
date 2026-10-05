<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import EmptyState from '../Components/EmptyState.vue';
import StatCard from '../Components/StatCard.vue';
import AppLayout from '../Layouts/AppLayout.vue';
</script>
<template>
    <Head title="Overzicht" /><AppLayout title="Overzicht" eyebrow="Werkplek">
        <section class="welcome-row"><div><p class="section-kicker">Overzicht werkplek</p><h2>Goedemorgen, Admin</h2><p class="muted-copy">Je CreatorSMP4-werkplek is klaar voor analyse.</p></div><div class="date-pill"><span class="live-dot" />Ontwikkelomgeving</div></section>
        <section class="stats-grid" aria-label="Statistieken werkplek"><StatCard label="Streams" value="0" icon="play" accent="blue" /><StatCard label="Spelers" value="0" icon="users" accent="violet" /><StatCard label="Events" value="0" icon="spark" accent="amber" /><StatCard label="Verwerking" value="Inactief" icon="clock" accent="green" /></section>
        <section class="dashboard-grid"><div class="panel"><div class="panel-heading"><div><p class="section-kicker">Bibliotheek</p><h3>Recente streams</h3></div><span class="panel-link">Alles bekijken <span>→</span></span></div><EmptyState title="Er zijn nog geen streams toegevoegd" description="Streams verschijnen hier zodra ze klaar zijn voor analyse." /></div><div class="panel"><div class="panel-heading"><div><p class="section-kicker">Analyse</p><h3>Servertijdlijn</h3></div><span class="panel-badge">Binnenkort</span></div><EmptyState icon="timeline" title="Nog geen events gevonden" description="Gevonden events komen op de tijdlijn." /></div></section>
    </AppLayout>
</template>
