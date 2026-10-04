<script setup lang="ts">
withDefaults(defineProps<{ label: string; value: string; icon: string; accent?: string; meta?: string }>(), { meta: 'No activity yet' });
</script>
<template>
    <div class="stat-card"><div class="stat-card-top"><span class="stat-label">{{ label }}</span><span class="stat-icon" :class="'stat-icon-' + (accent ?? 'blue')">
        <svg v-if="icon === 'play'" viewBox="0 0 20 20" aria-hidden="true"><path d="m7 5 7 5-7 5V5Z" /></svg>
        <svg v-else-if="icon === 'users'" viewBox="0 0 20 20" aria-hidden="true"><circle cx="8" cy="7" r="3" /><path d="M3 16c.4-2.4 2.2-4 5-4s4.6 1.6 5 4M14 5.5a2.5 2.5 0 0 1 0 4.8M15 12.3c1.2.5 1.9 1.4 2 2.7" /></svg>
        <svg v-else-if="icon === 'spark'" viewBox="0 0 20 20" aria-hidden="true"><path d="m10 2 1.5 5.2L17 9l-5.5 1.8L10 16l-1.5-5.2L3 9l5.5-1.8L10 2Z" /></svg>
        <svg v-else viewBox="0 0 20 20" aria-hidden="true"><path d="M10 3a7 7 0 1 0 6.6 9.4M10 6v4l2.5 1.5" /></svg>
    </span></div><div class="stat-value">{{ value }}</div><div class="stat-meta">{{ meta }}</div></div>
</template>
