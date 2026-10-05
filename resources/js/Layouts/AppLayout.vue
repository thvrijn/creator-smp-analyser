<script setup lang="ts">
import AppHeader from '../Components/AppHeader.vue';
import AppSidebar from '../Components/AppSidebar.vue';
// fill: the page fits the viewport and only the content inside it scrolls.
defineProps<{ title: string; eyebrow?: string; fill?: boolean }>();
</script>
<template><div class="app-shell"><AppSidebar /><main class="app-main" :class="{ 'app-main-fill': fill }"><AppHeader :title="title" :eyebrow="eyebrow" /><div class="page-content"><slot /></div></main></div></template>
