<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import DemoBadge from '../DemoBadge.vue';
import PlayerAvatar from '../PlayerAvatar.vue';
import { daysAgo, demoGoals, demoQuestions, demoRelation, relationLabels } from '../../composables/streamAnalysis';
import type { PlayerEvent, PlayerItem, SharedMoment } from '../../types/streamDetail';

// Who this player is in the story: their team and recent events (real), and goals, open questions and relations
// (example data until the analysis keeps track of them). wide: the Context tab shows all three parts side by side.
const props = defineProps<{ player: PlayerItem; playerEvents: PlayerEvent[]; others: PlayerItem[]; moments: SharedMoment[]; wide?: boolean }>();
type Tab = 'player' | 'others' | 'related';
const tab = ref<Tab>('player');
const tabs: { key: Tab; label: string }[] = [{ key: 'player', label: 'Deze speler' }, { key: 'others', label: 'Andere spelers' }, { key: 'related', label: 'Gerelateerd' }];
const momentUrl = (moment: SharedMoment) => '/streams/' + moment.stream_id + '?' + new URLSearchParams(moment.event_id !== null ? { event: String(moment.event_id) } : { tab: 'transcript', at: String(moment.at) });
const shows = (part: Tab) => props.wide || tab.value === part;
</script>

<template>
    <section class="rail-card context-panel" :class="{ 'context-wide': wide }">
        <h3 v-if="!wide">Context</h3>
        <div v-if="!wide" class="segmented" role="tablist">
            <button v-for="item in tabs" :key="item.key" type="button" role="tab" :aria-selected="tab === item.key" :class="{ 'segmented-active': tab === item.key }" @click="tab = item.key">{{ item.label }}</button>
        </div>

        <div v-if="shows('player')" class="context-part">
            <h4 v-if="wide" class="context-heading">Deze speler</h4>
            <div class="context-player">
                <PlayerAvatar :name="player.name" :photo-url="player.photo_url" size="md" />
                <span><strong>{{ player.name }}</strong><span>{{ player.team ? 'Team ' + player.team : 'Team onbekend' }}</span></span>
                <Link class="inline-link" :href="'/players/' + player.id">Profiel →</Link>
            </div>
            <h4 class="context-heading">Recente doelen <DemoBadge /></h4>
            <ul class="context-list"><li v-for="goal in demoGoals(player.id)" :key="goal">{{ goal }}</li></ul>
            <h4 class="context-heading">Recente events</h4>
            <ul v-if="playerEvents.length" class="context-list context-events">
                <li v-for="event in playerEvents" :key="event.id"><span>{{ daysAgo(event.at) }}</span><Link :href="'/streams/' + event.stream_id + '?event=' + event.id">{{ event.title }}</Link></li>
            </ul>
            <p v-else class="muted-copy">Nog geen events in andere streams van {{ player.name }}.</p>
            <h4 class="context-heading">Open vragen <DemoBadge /></h4>
            <ul class="context-list"><li v-for="question in demoQuestions(player.id)" :key="question">{{ question }}</li></ul>
        </div>

        <div v-if="shows('others')" class="context-part">
            <h4 v-if="wide" class="context-heading">Andere spelers</h4>
            <div v-if="others.length" class="context-people">
                <Link v-for="other in others" :key="other.id" class="context-person" :href="'/players/' + other.id">
                    <PlayerAvatar :name="other.name" :photo-url="other.photo_url" size="sm" />
                    <span><strong>{{ other.name }}</strong><span class="other-tags"><span v-if="other.team" class="team-chip" :class="'team-' + other.team.toLowerCase()">{{ other.team }}</span><span class="relation" :class="'relation-' + demoRelation(player.id, other.id)">{{ relationLabels[demoRelation(player.id, other.id)] }}</span></span></span>
                </Link>
            </div>
            <p v-else class="muted-copy">Nog geen andere spelers herkend in deze stream.</p>
            <p v-if="others.length" class="context-note"><DemoBadge title="De relatie tussen spelers wordt nog niet door de analyse bepaald." /> relaties</p>
        </div>

        <div v-if="shows('related')" class="context-part">
            <h4 v-if="wide" class="context-heading">Gerelateerd</h4>
            <ul v-if="moments.length" class="context-list context-moments">
                <li v-for="moment in moments" :key="moment.stream_id + '-' + (moment.event_id ?? moment.at)">
                    <Link :href="momentUrl(moment)"><PlayerAvatar :name="moment.player.name" :photo-url="moment.player.photo_url" size="sm" /><span><strong>{{ moment.player.name }}</strong><span>{{ moment.event_title ?? moment.stream_title }}</span></span></Link>
                </li>
            </ul>
            <p v-else class="muted-copy">Nog geen zelfde momenten in streams van andere spelers.</p>
        </div>
    </section>
</template>
