<script setup lang="ts">
defineProps<{ title: string; description: string; icon?: string }>();
</script>
<template>
    <div class="empty-state"><div class="empty-icon">
        <svg v-if="icon === 'timeline'" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16" /><circle cx="8" cy="7" r="2" /><circle cx="16" cy="12" r="2" /><circle cx="11" cy="17" r="2" /></svg>
        <svg v-else viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z" /><path d="M8 9h8M8 13h5M8 17h7" /></svg>
    </div><h3>{{ title }}</h3><p>{{ description }}</p></div>
</template>
