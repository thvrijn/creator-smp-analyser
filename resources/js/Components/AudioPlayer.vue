<script setup lang="ts">
import { computed, ref } from 'vue';
import { formatSeconds } from '../composables/streamStatus';

// The stream's audio with our own controls instead of the browser's. The media element only loads
// when playback starts (preload="metadata"), and seeking uses Range requests.
defineProps<{ src: string }>();
const emit = defineEmits<{ time: [seconds: number]; playing: [playing: boolean] }>();

const audio = ref<HTMLAudioElement | null>(null);
const current = ref(0);
const duration = ref(0);
const playing = ref(false);
const loading = ref(false);
const muted = ref(false);
const volume = ref(1);
const rate = ref(1);
const rates = [1, 1.25, 1.5, 2];

const progress = computed(() => (duration.value > 0 ? (current.value / duration.value) * 100 : 0));

const play = async () => {
    try { await audio.value?.play(); } catch { /* the browser refused to play */ }
};
const toggle = () => { if (audio.value?.paused) void play(); else audio.value?.pause(); };
const seek = (seconds: number) => {
    if (!audio.value) return;
    audio.value.currentTime = Math.max(0, duration.value > 0 ? Math.min(duration.value, seconds) : seconds);
    current.value = audio.value.currentTime;
};
const skip = (seconds: number) => seek(current.value + seconds);
const cycleRate = () => {
    rate.value = rates[(rates.indexOf(rate.value) + 1) % rates.length];
    if (audio.value) audio.value.playbackRate = rate.value;
};
const toggleMute = () => { muted.value = !muted.value; if (audio.value) audio.value.muted = muted.value; };
const setVolume = (value: number) => {
    volume.value = value;
    muted.value = value === 0;
    if (audio.value) { audio.value.volume = value; audio.value.muted = muted.value; }
};

const onTime = () => { current.value = audio.value?.currentTime ?? 0; emit('time', current.value); };
const onPlaying = (value: boolean) => { playing.value = value; emit('playing', value); };

/** Plays from a moment in the file: segment and event times are file times. */
const playFrom = async (seconds: number) => { seek(seconds); await play(); };
defineExpose({ playFrom });
</script>

<template>
    <div class="audio-player">
        <audio ref="audio" :src="src" preload="metadata"
            @loadedmetadata="duration = audio?.duration ?? 0" @durationchange="duration = audio?.duration ?? 0"
            @timeupdate="onTime" @play="onPlaying(true)" @pause="onPlaying(false)" @ended="onPlaying(false)"
            @waiting="loading = true" @playing="loading = false" @canplay="loading = false" />
        <button class="audio-button audio-play" type="button" :aria-label="playing ? 'Pauzeren' : 'Afspelen'" :title="playing ? 'Pauzeren' : 'Afspelen'" @click="toggle">
            <svg v-if="loading && playing" class="audio-spinner" viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="7" /></svg>
            <svg v-else-if="playing" viewBox="0 0 20 20" aria-hidden="true"><rect x="5" y="4" width="3.5" height="12" rx="1" /><rect x="11.5" y="4" width="3.5" height="12" rx="1" /></svg>
            <svg v-else viewBox="0 0 20 20" aria-hidden="true"><path d="M6.5 4.2v11.6a.8.8 0 0 0 1.2.7l9-5.8a.8.8 0 0 0 0-1.4l-9-5.8a.8.8 0 0 0-1.2.7Z" /></svg>
        </button>
        <button class="audio-button audio-skip" type="button" title="10 seconden terug" aria-label="10 seconden terug" @click="skip(-10)">−10</button>
        <button class="audio-button audio-skip" type="button" title="10 seconden vooruit" aria-label="10 seconden vooruit" @click="skip(10)">+10</button>
        <span class="audio-time">{{ formatSeconds(current) }}</span>
        <input class="audio-range audio-seek" type="range" min="0" :max="duration || 0" step="0.1" :value="current" :style="{ '--fill': progress + '%' }" aria-label="Positie" :disabled="!duration" @input="seek(Number(($event.target as HTMLInputElement).value))" />
        <span class="audio-time audio-time-total">{{ duration ? formatSeconds(duration) : '—' }}</span>
        <button class="audio-button audio-rate" type="button" title="Afspeelsnelheid" aria-label="Afspeelsnelheid" @click="cycleRate">{{ rate }}×</button>
        <button class="audio-button audio-mute" type="button" :title="muted ? 'Geluid aan' : 'Dempen'" :aria-label="muted ? 'Geluid aan' : 'Dempen'" @click="toggleMute">
            <svg viewBox="0 0 20 20" aria-hidden="true"><path class="audio-icon-fill" d="M3 8v4h3l4 3.5v-11L6 8H3Z" /><path v-if="!muted" d="M13 7.5a3.5 3.5 0 0 1 0 5M15.2 5.3a6.6 6.6 0 0 1 0 9.4" /><path v-else d="m13 8 4 4m0-4-4 4" /></svg>
        </button>
        <input class="audio-range audio-volume" type="range" min="0" max="1" step="0.05" :value="muted ? 0 : volume" :style="{ '--fill': (muted ? 0 : volume * 100) + '%' }" aria-label="Volume" @input="setVolume(Number(($event.target as HTMLInputElement).value))" />
    </div>
</template>
