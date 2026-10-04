<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage<{ flash?: { success?: string; error?: string } }>();
const successMessage = computed(() => page.props.flash?.success);
const errorMessage = computed(() => page.props.flash?.error);
</script>

<template>
    <div v-if="successMessage" class="success-banner" role="status">{{ successMessage }}</div>
    <div v-if="errorMessage" class="error-banner" role="alert">{{ errorMessage }}</div>
</template>
