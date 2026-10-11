<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProcessingPanel from './ProcessingPanel.vue';

type NavItem = { label: string; href: string; icon: string };
const page = usePage<{ auth: { user: { is_admin: boolean } | null } }>();
const currentPath = computed(() => page.url.split('?')[0]);
const main: NavItem[] = [
    { label: 'Dashboard', href: '/dashboard', icon: 'home' },
    { label: 'Streams', href: '/streams', icon: 'play' },
    { label: 'Spelers', href: '/players', icon: 'users' },
    { label: 'Events', href: '/events', icon: 'spark' },
    { label: 'Verhaallijnen', href: '/storylines', icon: 'book' },
    { label: 'Tijdlijn', href: '/timeline', icon: 'clock' },
    { label: 'Afleveringen', href: '/episodes', icon: 'tv' },
    { label: 'Media', href: '/video-builder', icon: 'image' },
];
const system = computed<NavItem[]>(() => [
    { label: 'Instellingen', href: '/settings', icon: 'settings' },
    // Only the admin manages accounts and reads the activity log.
    ...(page.props.auth?.user?.is_admin ? [{ label: 'Gebruikers', href: '/users', icon: 'users' }, { label: 'Activiteit', href: '/activity', icon: 'list' }] : []),
]);
// A stream page belongs to Streams.
const isActive = (href: string) => currentPath.value === href || currentPath.value.startsWith(href + '/');
</script>

<template>
    <aside class="app-sidebar">
        <Link href="/dashboard" class="sidebar-brand">
            <span class="brand-mark">C</span><span class="brand-name">Creator<span>SMP4</span></span>
        </Link>
        <nav class="sidebar-nav" aria-label="Hoofdnavigatie">
            <template v-for="(group, groupIndex) in [main, system]" :key="groupIndex">
                <div class="nav-section" :class="{ 'nav-section-bottom': groupIndex === 1 }">
                    <Link v-for="item in group" :key="item.href" :href="item.href" class="nav-item" :class="{ 'nav-item-active': isActive(item.href) }" :aria-current="isActive(item.href) ? 'page' : undefined">
                        <svg viewBox="0 0 20 20" aria-hidden="true">
                            <path v-if="item.icon === 'home'" d="M3 9.5 10 4l7 5.5V16a1 1 0 0 1-1 1h-3.5v-4.5h-5V17H4a1 1 0 0 1-1-1V9.5Z" />
                            <template v-else-if="item.icon === 'play'"><rect x="3" y="4" width="14" height="12" rx="2" /><path d="m8.5 7.5 4 2.5-4 2.5v-5Z" /></template>
                            <template v-else-if="item.icon === 'users'"><circle cx="8" cy="7" r="3" /><path d="M3 16c.4-2.4 2.2-4 5-4s4.6 1.6 5 4M14 5.5a2.5 2.5 0 0 1 0 4.8M15 12.3c1.2.5 1.9 1.4 2 2.7" /></template>
                            <path v-else-if="item.icon === 'spark'" d="m10 2.5 1.6 4.4 4.9.4-3.8 3 1.2 4.7L10 12.4 6.1 15l1.2-4.7-3.8-3 4.9-.4L10 2.5Z" />
                            <template v-else-if="item.icon === 'book'"><path d="M4 4.5A1.5 1.5 0 0 1 5.5 3H16v12H5.5A1.5 1.5 0 0 0 4 16.5v-12Z" /><path d="M4 16.5A1.5 1.5 0 0 0 5.5 18H16M8 7h5" /></template>
                            <template v-else-if="item.icon === 'clock'"><circle cx="10" cy="10" r="7" /><path d="M10 6v4l2.5 1.5" /></template>
                            <template v-else-if="item.icon === 'tv'"><rect x="3" y="5" width="14" height="10" rx="2" /><path d="M7 18h6M8 2.5l2 2.5 2-2.5" /></template>
                            <template v-else-if="item.icon === 'image'"><rect x="3" y="4" width="14" height="12" rx="2" /><circle cx="7.5" cy="8" r="1.5" /><path d="m4 14 4-4 3 3 2-2 3 3" /></template>
                            <template v-else-if="item.icon === 'list'"><path d="M7 5h10M7 10h10M7 15h10" /><circle cx="3.8" cy="5" r=".8" /><circle cx="3.8" cy="10" r=".8" /><circle cx="3.8" cy="15" r=".8" /></template>
                            <template v-else><circle cx="10" cy="10" r="2.5" /><path d="M10 2.5v2M10 15.5v2M17.5 10h-2M4.5 10h-2M15.3 4.7l-1.4 1.4M6.1 13.9l-1.4 1.4M15.3 15.3l-1.4-1.4M6.1 6.1 4.7 4.7" /></template>
                        </svg>
                        <span>{{ item.label }}</span>
                    </Link>
                </div>
            </template>
        </nav>
        <ProcessingPanel />
    </aside>
</template>
