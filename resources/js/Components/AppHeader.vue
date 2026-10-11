<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import DemoBadge from './DemoBadge.vue';
import GlobalSearch from './GlobalSearch.vue';
import { workerTaskLabel, type Worker } from '../types/workers';

// The top bar: search, season, workers, settings, notifications and the profile menu.
const page = usePage<{ workers: Worker[]; auth: { user: { username: string; name: string } | null } }>();
const user = computed(() => page.props.auth?.user);
const openMenu = ref<'profile' | 'season' | 'notifications' | null>(null);
const toggle = (menu: 'profile' | 'season' | 'notifications') => { openMenu.value = openMenu.value === menu ? null : menu; };
const logout = () => router.post('/logout');
const usable = computed(() => (page.props.workers ?? []).filter((worker) => worker.online && worker.enabled));
const workerSummary = computed(() => usable.value.length === 0 ? 'Geen worker' : usable.value.length === 1 ? '1 worker' : usable.value.length + ' workers');
const workerTooltip = computed(() => usable.value.map((worker) => worker.name + (worker.current_task ? ': ' + workerTaskLabel(worker.current_task) : ': vrij')).join('\n') || 'Start een worker op je laptop, pc of Mac (make remote-worker).');
// Only season 4 exists; the others show where the selector is going.
const seasons = [{ label: 'SMP Seizoen 4', current: true }, { label: 'SMP Seizoen 3', current: false }];
const notifications = [
    { title: 'Analyse klaar', detail: 'Jeremy Frieser – stream van gisteren: 17 events', when: '2 uur geleden' },
    { title: 'Zelfde moment gevonden', detail: 'Egbertlive en Morrog praten over de wijkruil', when: 'gisteren' },
];

// Workers come and go (a laptop is closed); refresh just this prop now and then.
let timer: number | undefined;
// A click anywhere else closes the open menu.
const closeMenu = () => { openMenu.value = null; };
onMounted(() => {
    timer = window.setInterval(() => router.reload({ only: ['workers'] }), 15000);
    document.addEventListener('click', closeMenu);
});
onBeforeUnmount(() => {
    window.clearInterval(timer);
    document.removeEventListener('click', closeMenu);
});
</script>
<template>
    <header class="app-header">
        <GlobalSearch />
        <div class="header-actions">
            <div class="header-menu" @click.stop>
                <button class="season-select" type="button" :aria-expanded="openMenu === 'season'" aria-haspopup="menu" @click="toggle('season')">SMP Seizoen 4<span class="profile-chevron">⌄</span></button>
                <div v-if="openMenu === 'season'" class="profile-dropdown" role="menu">
                    <p class="profile-dropdown-email">Seizoen <DemoBadge title="Er is alleen seizoen 4: het kiezen van een seizoen is nog niet gebouwd." /></p>
                    <button v-for="season in seasons" :key="season.label" type="button" role="menuitem" :disabled="!season.current" @click="openMenu = null">{{ season.label }}<span v-if="season.current"> ✓</span></button>
                </div>
            </div>
            <Link href="/settings" class="worker-pill" :class="{ 'worker-pill-offline': usable.length === 0 }" :title="workerTooltip"><span class="status-dot" />{{ workerSummary }}</Link>
            <Link href="/settings" class="icon-button" title="Instellingen" aria-label="Instellingen">
                <svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="2.5" /><path d="M10 2.5v2M10 15.5v2M17.5 10h-2M4.5 10h-2M15.3 4.7l-1.4 1.4M6.1 13.9l-1.4 1.4M15.3 15.3l-1.4-1.4M6.1 6.1 4.7 4.7" /></svg>
            </Link>
            <div class="header-menu" @click.stop>
                <button class="icon-button" type="button" title="Meldingen" aria-label="Meldingen" :aria-expanded="openMenu === 'notifications'" aria-haspopup="menu" @click="toggle('notifications')">
                    <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M5 13.5V9a5 5 0 0 1 10 0v4.5l1.5 2h-13l1.5-2ZM8.5 17.5a1.6 1.6 0 0 0 3 0" /></svg>
                    <span class="icon-button-dot" />
                </button>
                <div v-if="openMenu === 'notifications'" class="profile-dropdown notification-dropdown" role="menu">
                    <p class="profile-dropdown-email">Meldingen <DemoBadge title="Meldingen zijn nog niet gebouwd: dit zijn voorbeelden." /></p>
                    <div v-for="item in notifications" :key="item.title" class="notification-item"><strong>{{ item.title }}</strong><span>{{ item.detail }}</span><small>{{ item.when }}</small></div>
                </div>
            </div>
            <div v-if="user" class="header-menu profile-menu" @click.stop>
                <button class="profile-chip" type="button" :aria-expanded="openMenu === 'profile'" aria-haspopup="menu" :title="user.name + ' (@' + user.username + ')'" @click="toggle('profile')"><span class="profile-avatar">{{ user.name.charAt(0).toUpperCase() }}</span></button>
                <div v-if="openMenu === 'profile'" class="profile-dropdown" role="menu">
                    <p class="profile-dropdown-email"><strong>{{ user.name }}</strong><br>@{{ user.username }}</p>
                    <Link href="/profile" role="menuitem" @click="openMenu = null">Profiel en wachtwoord</Link>
                    <button type="button" role="menuitem" @click="logout">Uitloggen</button>
                </div>
            </div>
        </div>
    </header>
</template>
