<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import EmptyState from '../Components/EmptyState.vue';
import PlayerAvatar from '../Components/PlayerAvatar.vue';
import { formatSeconds } from '../composables/streamStatus';
import AppLayout from '../Layouts/AppLayout.vue';
import type { PlayerRef } from '../types/streams';

type BuilderClip = { id: number; title: string; start_seconds: number; end_seconds: number; starts_at: string; stream: { id: number; title: string }; player: PlayerRef };

const props = defineProps<{ clips: BuilderClip[] }>();
const day = (iso: string) => new Intl.DateTimeFormat('nl-NL', { weekday: 'long', day: 'numeric', month: 'long' }).format(new Date(iso));
const time = (iso: string) => new Date(iso).toLocaleTimeString('nl-NL', { hour: '2-digit', minute: '2-digit', second: '2-digit' });

// Grouped per day, in the order it happened on the server. A clip that starts before the previous one ended (from another
// player) is the same moment from another perspective.
const days = computed(() => {
    const groups: { day: string; clips: (BuilderClip & { sameMoment: boolean })[] }[] = [];
    let previousEnd = 0;
    let previousPlayer: number | null = null;
    for (const clip of props.clips) {
        const start = Date.parse(clip.starts_at);
        const label = day(clip.starts_at);
        if (groups.at(-1)?.day !== label) groups.push({ day: label, clips: [] });
        groups.at(-1)!.clips.push({ ...clip, sameMoment: start < previousEnd && clip.player.id !== previousPlayer });
        previousEnd = Math.max(previousEnd, start + (clip.end_seconds - clip.start_seconds) * 1000);
        previousPlayer = clip.player.id;
    }
    return groups;
});
</script>

<template>
    <Head title="Videobouwer" />
    <AppLayout title="Videobouwer" eyebrow="Media">
        <div class="streams-toolbar"><div><p class="section-kicker">Media</p><h2 class="page-section-title">Clips</h2><p class="muted-copy">Alle gekozen momenten van alle spelers, in de volgorde waarin ze op de server gebeurden.</p></div></div>
        <template v-if="clips.length">
            <section v-for="group in days" :key="group.day" class="player-group" :aria-label="group.day">
                <h3 class="section-kicker player-group-title">{{ group.day }} <span>{{ group.clips.length }}</span></h3>
                <div class="streams-panel"><div class="streams-table-wrap"><table class="streams-table">
                    <thead><tr><th>Tijd</th><th>Speler</th><th>Clip</th><th>Duur</th></tr></thead>
                    <tbody>
                        <tr v-for="clip in group.clips" :key="clip.id">
                            <td>{{ time(clip.starts_at) }}<span v-if="clip.sameMoment" class="same-moment">zelfde moment</span></td>
                            <td><Link class="player-cell" :href="'/players/' + clip.player.id"><PlayerAvatar :name="clip.player.name" :photo-url="clip.player.photo_url" size="sm" />{{ clip.player.name }}</Link></td>
                            <td><Link class="stream-title" :href="'/streams/' + clip.stream.id + '?tab=clips&clip=' + clip.id">{{ clip.title }}</Link><div class="stream-source">{{ clip.stream.title }}</div></td>
                            <td>{{ formatSeconds(clip.end_seconds - clip.start_seconds) }}</td>
                        </tr>
                    </tbody>
                </table></div></div>
            </section>
        </template>
        <div v-else class="standalone-panel"><EmptyState title="Nog geen clips" description="Open een stream en klik op “＋ Clip” bij een event of “✂ Clip” in het transcript." /></div>
    </AppLayout>
</template>
