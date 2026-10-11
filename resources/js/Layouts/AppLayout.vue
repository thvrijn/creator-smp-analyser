<script setup lang="ts">
import AppHeader from '../Components/AppHeader.vue';
import AppSidebar from '../Components/AppSidebar.vue';
// bare: the page shows its own title (the stream page).
defineProps<{ title: string; eyebrow?: string; bare?: boolean }>();
</script>
<template>
    <div class="app-shell">
        <AppSidebar />
        <main class="app-main">
            <AppHeader />
            <div class="page-content">
                <div v-if="!bare" class="page-title"><p v-if="eyebrow" class="header-eyebrow">{{ eyebrow }}</p><h1>{{ title }}</h1></div>
                <slot />
            </div>
        </main>
    </div>
</template>
