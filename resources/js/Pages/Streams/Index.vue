<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import AddStreamModal from '../../Components/AddStreamModal.vue';
import EmptyState from '../../Components/EmptyState.vue';
import FlashMessages from '../../Components/FlashMessages.vue';
import StreamsTable from '../../Components/StreamsTable.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import type { PlayerOption, Stream } from '../../types/streams';

defineProps<{ streams: Stream[]; players: PlayerOption[] }>();
const showForm = ref(false);
</script>

<template>
    <Head title="Streams" />
    <AppLayout title="Streams" eyebrow="Analyse">
        <div class="streams-toolbar"><div><p class="section-kicker">Bibliotheek</p><h2 class="page-section-title">Je streams</h2><p class="muted-copy">Beheer de opnames die klaar zijn voor analyse.</p></div><button class="primary-button" type="button" @click="showForm = true"><span>＋</span> Stream toevoegen</button></div>
        <FlashMessages />
        <StreamsTable v-if="streams.length" :streams="streams" />
        <div v-else class="standalone-panel"><EmptyState title="Nog geen streams" description="Voeg je eerste stream toe om je analysebibliotheek op te bouwen." /></div>
        <AddStreamModal v-model:open="showForm" :players="players" />
    </AppLayout>
</template>
