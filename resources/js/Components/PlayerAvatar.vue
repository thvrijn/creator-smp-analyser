<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(defineProps<{ name: string; size?: 'md' | 'lg' }>(), { size: 'md' });
// A stable hue per name, so a player keeps the same colour everywhere.
const hue = computed(() => [...props.name].reduce((total, character) => (total * 31 + character.charCodeAt(0)) % 360, 7));
</script>

<template>
    <span class="player-avatar-large" :class="'player-avatar-' + size" :style="{ '--avatar-hue': hue }" aria-hidden="true">{{ name.charAt(0).toUpperCase() }}</span>
</template>
