<script setup lang="ts">
// Marks example data: parts of the design the analysis does not fill yet.
withDefaults(defineProps<{ title?: string }>(), { title: 'Voorbeelddata: dit wordt nog niet door de analyse bepaald.' });
</script>
<template><span class="demo-badge" :title="title">voorbeeld</span></template>
