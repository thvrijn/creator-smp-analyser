<script setup lang="ts">
defineProps<{ title: string; eyebrow?: string }>();
</script>
<template>
    <header class="app-header">
        <div><p v-if="eyebrow" class="header-eyebrow">{{ eyebrow }}</p><h1>{{ title }}</h1></div>
        <div class="header-actions">
            <button class="icon-button" aria-label="Notifications"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M15 8a5 5 0 0 0-10 0c0 5-2 5-2 6h14c0-1-2-1-2-6ZM8 17h4" /></svg></button>
            <div class="profile-chip"><span class="profile-avatar">A</span><span class="profile-name">Admin</span><span class="profile-chevron">⌄</span></div>
        </div>
    </header>
</template>
