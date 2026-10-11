<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import DemoBadge from '../Components/DemoBadge.vue';
import AppLayout from '../Layouts/AppLayout.vue';

// Not built yet: storylines that run through several players' streams (see TODO.md, "Verhalen samenvoegen").
const storylines = [
    { title: 'De wijkruil', status: 'Loopt', players: ['Jeremy Frieser', 'Morrog'], summary: 'Twee wijken onderhandelen over een ruil van grond. Het gesprek loopt over meerdere avonden en de vraag is wie er eerlijk is.', events: 17, days: 3 },
    { title: 'Wantrouwen in het noorden', status: 'Loopt', players: ['Egbertlive', 'Jamie'], summary: 'Na een verdwenen kist verdenken de buren elkaar. Er worden plannen gesmeed voor een confrontatie.', events: 9, days: 2 },
    { title: 'De eerste nacht', status: 'Afgerond', players: ['Iedereen'], summary: 'De opening van het seizoen: iedereen zoekt een plek, de eerste allianties ontstaan.', events: 24, days: 1 },
];
</script>
<template>
    <Head title="Verhaallijnen" />
    <AppLayout title="Verhaallijnen" eyebrow="Analyse">
        <p class="muted-copy page-intro">Verhaallijnen volgen één verhaal door de streams van meerdere spelers heen. <DemoBadge title="Verhaallijnen zijn nog niet gebouwd: dit zijn voorbeelden." /></p>
        <div class="demo-card-grid">
            <article v-for="story in storylines" :key="story.title" class="analysis-card demo-card">
                <div class="analysis-card-head"><h3>{{ story.title }}</h3><span class="status-chip" :class="story.status === 'Loopt' ? 'status-analyzing' : 'status-done'">{{ story.status }}</span></div>
                <p class="muted-copy">{{ story.summary }}</p>
                <p class="demo-card-meta">{{ story.players.join(', ') }} · {{ story.events }} events · {{ story.days }} {{ story.days === 1 ? 'dag' : 'dagen' }}</p>
            </article>
        </div>
    </AppLayout>
</template>
