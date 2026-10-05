<script setup lang="ts">
import { onBeforeUnmount, onMounted, watch } from 'vue';

// Twitch's embedded player: it previews any moment of a VOD without downloading it.
type TwitchPlayerApi = { seek(seconds: number): void; play(): void; pause(): void; getCurrentTime(): number };
declare global { interface Window { Twitch?: { Player: new (id: string, options: Record<string, unknown>) => TwitchPlayerApi } } }

const props = defineProps<{ videoId: string; start: number; end: number }>();
const elementId = 'twitch-player-' + Math.random().toString(36).slice(2);
let player: TwitchPlayerApi | null = null;
let stopTimer: number | undefined;

const loadScript = () => new Promise<void>((resolve) => {
    if (window.Twitch?.Player) return resolve();
    const script = document.createElement('script');
    script.src = 'https://player.twitch.tv/js/embed/v1.js';
    script.onload = () => resolve();
    document.head.appendChild(script);
});
const twitchTime = (seconds: number) => {
    const total = Math.max(0, Math.floor(seconds));
    return `${Math.floor(total / 3600)}h${Math.floor((total % 3600) / 60)}m${total % 60}s`;
};

onMounted(async () => {
    await loadScript();
    // Twitch only plays inside pages whose host is listed as parent (localhost works without HTTPS).
    player = new window.Twitch!.Player(elementId, { video: 'v' + props.videoId, time: twitchTime(props.start), autoplay: false, parent: [window.location.hostname], width: '100%', height: '100%' });
});
onBeforeUnmount(() => window.clearInterval(stopTimer));
// Adjusting the start (or picking another clip) shows the new start right away.
watch(() => props.start, (seconds) => player?.seek(seconds));

/** Plays the clip from its start and pauses at its end. */
const play = () => {
    if (!player) return;
    player.seek(props.start);
    player.play();
    window.clearInterval(stopTimer);
    stopTimer = window.setInterval(() => {
        if (player && player.getCurrentTime() >= props.end) {
            player.pause();
            window.clearInterval(stopTimer);
        }
    }, 250);
};
defineExpose({ play });
</script>

<template>
    <div :id="elementId" class="twitch-player" />
</template>
