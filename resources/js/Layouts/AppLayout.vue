<script setup lang="ts">
import AppHeader from '../Components/AppHeader.vue';
import AppSidebar from '../Components/AppSidebar.vue';
defineProps<{ title: string; eyebrow?: string }>();
</script>
<template><div class="app-shell"><AppSidebar /><main class="app-main"><AppHeader :title="title" :eyebrow="eyebrow" /><div class="page-content"><slot /></div></main></div></template>
