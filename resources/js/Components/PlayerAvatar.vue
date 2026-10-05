<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(defineProps<{ name: string; photoUrl?: string | null; size?: 'sm' | 'md' | 'lg' }>(), { photoUrl: null, size: 'md' });
// A stable hue per name, so a player without a photo keeps the same colour everywhere.
const hue = computed(() => [...props.name].reduce((total, character) => (total * 31 + character.charCodeAt(0)) % 360, 7));
</script>

<template>
    <span class="player-avatar-large" :class="'player-avatar-' + size" :style="{ '--avatar-hue': hue }" aria-hidden="true">
        <img v-if="photoUrl" :src="photoUrl" alt="" loading="lazy" />
        <template v-else>{{ name.charAt(0).toUpperCase() }}</template>
    </span>
</template>
