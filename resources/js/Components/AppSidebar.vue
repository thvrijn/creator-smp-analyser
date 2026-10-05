<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

type NavItem = { label: string; href: string; icon: string };
const page = usePage();
const currentPath = computed(() => page.url.split('?')[0]);
const sections: { label: string; items: NavItem[] }[] = [
    { label: '', items: [{ label: 'Dashboard', href: '/dashboard', icon: 'grid' }, { label: 'Overzicht', href: '/overview', icon: 'timeline' }] },
    { label: 'Analyse', items: [
        { label: 'Streams', href: '/streams', icon: 'play' },
        { label: 'Spelers', href: '/players', icon: 'users' },
        { label: 'Tijdlijn', href: '/timeline', icon: 'timeline' },
        { label: 'Events', href: '/events', icon: 'spark' },
    ] },
    { label: 'Media', items: [{ label: 'Videobouwer', href: '/video-builder', icon: 'film' }] },
    { label: 'Systeem', items: [{ label: 'Instellingen', href: '/settings', icon: 'settings' }] },
];
const isActive = (href: string) => currentPath.value === href || currentPath.value.startsWith(href + '/');
</script>

<template>
    <aside class="app-sidebar">
        <div class="sidebar-brand">
            <div class="brand-mark">C</div>
            <div><span class="brand-name">Creator<span>SMP4</span></span><span class="brand-subtitle">Analyseomgeving</span></div>
        </div>
        <nav class="sidebar-nav" aria-label="Hoofdnavigatie">
            <div v-for="section in sections" :key="section.label || 'dashboard'" class="nav-section">
                <p v-if="section.label" class="nav-section-label">{{ section.label }}</p>
                <Link v-for="item in section.items" :key="item.href" :href="item.href" class="nav-item" :class="{ 'nav-item-active': isActive(item.href) }" :aria-current="isActive(item.href) ? 'page' : undefined">
                    <svg v-if="item.icon === 'grid'" viewBox="0 0 20 20" aria-hidden="true"><rect x="3" y="3" width="5" height="5" rx="1" /><rect x="12" y="3" width="5" height="5" rx="1" /><rect x="3" y="12" width="5" height="5" rx="1" /><rect x="12" y="12" width="5" height="5" rx="1" /></svg>
                    <svg v-else-if="item.icon === 'play'" viewBox="0 0 20 20" aria-hidden="true"><rect x="3" y="4" width="14" height="12" rx="2" /><path d="m8 7 5 3-5 3V7Z" /></svg>
                    <svg v-else-if="item.icon === 'users'" viewBox="0 0 20 20" aria-hidden="true"><circle cx="8" cy="7" r="3" /><path d="M3 16c.4-2.4 2.2-4 5-4s4.6 1.6 5 4M14 5.5a2.5 2.5 0 0 1 0 4.8M15 12.3c1.2.5 1.9 1.4 2 2.7" /></svg>
                    <svg v-else-if="item.icon === 'timeline'" viewBox="0 0 20 20" aria-hidden="true"><path d="M3 5h14M3 10h14M3 15h14" /><circle cx="6" cy="5" r="1.5" /><circle cx="13" cy="10" r="1.5" /><circle cx="9" cy="15" r="1.5" /></svg>
                    <svg v-else-if="item.icon === 'spark'" viewBox="0 0 20 20" aria-hidden="true"><path d="m10 2 1.5 5.2L17 9l-5.5 1.8L10 16l-1.5-5.2L3 9l5.5-1.8L10 2ZM16 14l.5 1.5L18 16l-1.5.5L16 18l-.5-1.5L14 16l1.5-.5L16 14Z" /></svg>
                    <svg v-else-if="item.icon === 'film'" viewBox="0 0 20 20" aria-hidden="true"><rect x="3" y="4" width="14" height="12" rx="2" /><path d="M7 4v12M13 4v12M3 8h4M13 8h4M3 12h4M13 12h4" /></svg>
                    <svg v-else viewBox="0 0 20 20" aria-hidden="true"><path d="M10 3a7 7 0 1 0 6.6 9.4M10 6v4l2.5 1.5" /><path d="m15 3 .5 2.5L18 6" /></svg>
                    <span>{{ item.label }}</span><span v-if="isActive(item.href)" class="nav-active-dot" />
                </Link>
            </div>
        </nav>
        <div class="sidebar-footer"><div class="system-status"><span class="status-dot" />Alle systemen werken</div><span class="version-label">v0.1.0 · Ontwikkeling</span></div>
    </aside>
</template>
