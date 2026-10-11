<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import PlayerAvatar from './PlayerAvatar.vue';
import { eventTypeLabel, formatSeconds } from '../composables/streamStatus';
import { formatDate } from '../types/streams';

// The search field in the top bar (GET /search): players, streams and events. "/" focuses it from anywhere,
// the arrow keys walk the results and Enter opens one.
type Results = {
    players: { id: number; name: string; photo_url: string | null }[];
    streams: { id: number; title: string; player: string; started_at: string | null }[];
    events: { id: number; stream_id: number; type: string; title: string; player: string; start_time: number }[];
};
type Item = { key: string; href: string; group: string; title: string; detail: string; photo?: { name: string; url: string | null } };

const input = ref<HTMLInputElement | null>(null);
const query = ref('');
const open = ref(false);
const loading = ref(false);
const results = ref<Results>({ players: [], streams: [], events: [] });
const activeIndex = ref(0);

const items = computed<Item[]>(() => [
    ...results.value.players.map((player) => ({ key: 'p' + player.id, href: '/players/' + player.id, group: 'Spelers', title: player.name, detail: '', photo: { name: player.name, url: player.photo_url } })),
    ...results.value.streams.map((stream) => ({ key: 's' + stream.id, href: '/streams/' + stream.id, group: 'Streams', title: stream.title, detail: stream.player + ' · ' + formatDate(stream.started_at) })),
    ...results.value.events.map((event) => ({ key: 'e' + event.id, href: '/streams/' + event.stream_id + '?event=' + event.id, group: 'Events', title: event.title, detail: event.player + ' · ' + (event.type === 'other' ? '' : eventTypeLabel(event.type) + ' · ') + formatSeconds(event.start_time) })),
]);
const groups = computed(() => ['Spelers', 'Streams', 'Events'].map((name) => ({ name, items: items.value.filter((item) => item.group === name) })).filter((group) => group.items.length));

let timer: number | undefined;
let controller: AbortController | undefined;
watch(query, (value) => {
    window.clearTimeout(timer);
    activeIndex.value = 0;
    if (value.trim().length < 2) { results.value = { players: [], streams: [], events: [] }; return; }
    timer = window.setTimeout(async () => {
        controller?.abort();
        controller = new AbortController();
        loading.value = true;
        try {
            const response = await fetch('/search?' + new URLSearchParams({ q: value.trim() }), { headers: { Accept: 'application/json' }, signal: controller.signal });
            if (response.ok) results.value = await response.json() as Results;
        } catch { /* aborted by the next keystroke */ } finally { loading.value = false; }
    }, 200);
});

const go = (item: Item | undefined) => {
    if (!item) return;
    open.value = false;
    query.value = '';
    input.value?.blur();
    router.visit(item.href);
};
const move = (delta: number) => {
    if (!items.value.length) return;
    activeIndex.value = (activeIndex.value + delta + items.value.length) % items.value.length;
};
const onShortcut = (event: KeyboardEvent) => {
    const target = event.target as HTMLElement;
    if (event.key === '/' && !['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName) && !target.isContentEditable) {
        event.preventDefault();
        input.value?.focus();
    }
};
const close = () => { open.value = false; };
onMounted(() => { document.addEventListener('keydown', onShortcut); document.addEventListener('click', close); });
onBeforeUnmount(() => { document.removeEventListener('keydown', onShortcut); document.removeEventListener('click', close); window.clearTimeout(timer); });
</script>

<template>
    <div class="global-search" @click.stop>
        <svg class="global-search-icon" viewBox="0 0 20 20" aria-hidden="true"><circle cx="9" cy="9" r="5.5" /><path d="m13.2 13.2 3.8 3.8" /></svg>
        <input ref="input" v-model="query" type="search" placeholder="Zoek spelers, events, streams…" aria-label="Zoeken" autocomplete="off"
            @focus="open = true" @input="open = true" @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)" @keydown.enter.prevent="go(items[activeIndex])" @keydown.esc="open = false; input?.blur()" />
        <kbd class="global-search-key" aria-hidden="true">/</kbd>
        <div v-if="open && query.trim().length >= 2" class="global-search-results" role="listbox">
            <template v-for="group in groups" :key="group.name">
                <p class="global-search-group">{{ group.name }}</p>
                <button v-for="item in group.items" :key="item.key" type="button" role="option" class="global-search-item" :class="{ 'global-search-active': items[activeIndex]?.key === item.key }" :aria-selected="items[activeIndex]?.key === item.key" @mouseenter="activeIndex = items.indexOf(item)" @click="go(item)">
                    <PlayerAvatar v-if="item.photo" :name="item.photo.name" :photo-url="item.photo.url" size="sm" />
                    <span><strong>{{ item.title }}</strong><span v-if="item.detail">{{ item.detail }}</span></span>
                </button>
            </template>
            <p v-if="!groups.length" class="global-search-empty">{{ loading ? 'Zoeken…' : 'Niets gevonden voor “' + query.trim() + '”' }}</p>
        </div>
    </div>
</template>
