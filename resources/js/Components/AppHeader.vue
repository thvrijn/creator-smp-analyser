<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { workerTaskLabel, type Worker } from '../types/workers';

defineProps<{ title: string; eyebrow?: string }>();

const page = usePage<{ workers: Worker[]; auth: { user: { username: string; name: string } | null } }>();
const user = computed(() => page.props.auth?.user);
const menuOpen = ref(false);
const logout = () => router.post('/logout');
const usable = computed(() => (page.props.workers ?? []).filter((worker) => worker.online && worker.enabled));
const workerSummary = computed(() => usable.value.length === 0 ? 'Geen worker online' : usable.value.length === 1 ? '1 worker online' : usable.value.length + ' workers online');
const workerTooltip = computed(() => usable.value.map((worker) => worker.name + (worker.current_task ? ': ' + workerTaskLabel(worker.current_task) : ': vrij')).join('\n') || 'Start een worker op je laptop, pc of Mac (make remote-worker).');

// Workers come and go (a laptop is closed); refresh just this prop now and then.
let timer: number | undefined;
// A click anywhere else closes the profile menu.
const closeMenu = () => { menuOpen.value = false; };
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
        <div><p v-if="eyebrow" class="header-eyebrow">{{ eyebrow }}</p><h1>{{ title }}</h1></div>
        <div class="header-actions">
            <Link href="/settings" class="worker-pill" :class="{ 'worker-pill-offline': usable.length === 0 }" :title="workerTooltip"><span class="status-dot" />{{ workerSummary }}</Link>
            <div v-if="user" class="profile-menu" @click.stop>
                <button class="profile-chip" type="button" :aria-expanded="menuOpen" aria-haspopup="menu" :title="'@' + user.username" @click="menuOpen = !menuOpen"><span class="profile-avatar">{{ user.name.charAt(0).toUpperCase() }}</span><span class="profile-name">{{ user.name }}</span><span class="profile-chevron">⌄</span></button>
                <div v-if="menuOpen" class="profile-dropdown" role="menu">
                    <p class="profile-dropdown-email">@{{ user.username }}</p>
                    <Link href="/profile" role="menuitem" @click="menuOpen = false">Profiel en wachtwoord</Link>
                    <button type="button" role="menuitem" @click="logout">Uitloggen</button>
                </div>
            </div>
        </div>
    </header>
</template>
