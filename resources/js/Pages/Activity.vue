<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import EmptyState from '../Components/EmptyState.vue';
import AppLayout from '../Layouts/AppLayout.vue';
import { formatDate } from '../types/streams';

type Entry = {
    id: number; at: string | null; username: string | null; description: string;
    subject_type: string | null; subject_id: number | null; subject_label: string | null;
    result: string | null; succeeded: boolean; count: number; ip_address: string | null;
};
const props = defineProps<{ entries: Entry[]; pagination: { current: number; last: number; total: number }; usernames: string[]; filter: { user: string } }>();

const subjectUrl = (entry: Entry) => entry.subject_id === null ? null : entry.subject_type === 'stream' ? '/streams/' + entry.subject_id : entry.subject_type === 'player' ? '/players/' + entry.subject_id : null;
const go = (params: { user?: string; page?: number }) => router.get('/activity', {
    ...(params.user ?? props.filter.user ? { user: params.user ?? props.filter.user } : {}),
    ...(params.page && params.page > 1 ? { page: params.page } : {}),
}, { preserveScroll: true, preserveState: true });
const filterUser = (event: Event) => go({ user: (event.target as HTMLSelectElement).value, page: 1 });
</script>

<template>
    <Head title="Activiteit" />
    <AppLayout title="Activiteit" eyebrow="Systeem">
        <div class="standalone-panel streams-panel">
            <div class="panel-heading">
                <div><h3>Wat er gedaan is</h3><p class="muted-copy">Inloggen, uitloggen en alles wat iemand in de app verandert. Alleen jij als admin ziet dit.</p></div>
                <div class="form-field activity-filter"><label for="activity-user">Gebruiker</label>
                    <select id="activity-user" :value="filter.user" @change="filterUser"><option value="">Iedereen</option><option v-for="name in usernames" :key="name" :value="name">{{ name }}</option></select>
                </div>
            </div>
            <EmptyState v-if="entries.length === 0" title="Nog geen activiteit" description="Hier verschijnt wat er in de app gedaan wordt." />
            <div v-else class="streams-table-wrap"><table class="streams-table activity-table">
                <thead><tr><th>Wanneer</th><th>Wie</th><th>Wat</th><th>Waarop</th><th>Resultaat</th><th>IP</th></tr></thead>
                <tbody>
                    <tr v-for="entry in entries" :key="entry.id">
                        <td class="activity-when">{{ formatDate(entry.at) }}</td>
                        <td><strong>{{ entry.username ?? '—' }}</strong></td>
                        <td><span :class="{ 'activity-failed': !entry.succeeded }">{{ entry.description }}</span><span v-if="entry.count > 1" class="activity-count">{{ entry.count }}×</span></td>
                        <td><Link v-if="subjectUrl(entry)" class="inline-link" :href="subjectUrl(entry)!">{{ entry.subject_label ?? '#' + entry.subject_id }}</Link><template v-else>{{ entry.subject_label ?? '—' }}</template></td>
                        <td class="activity-result" :class="{ 'activity-failed': !entry.succeeded }">{{ entry.result ?? '' }}</td>
                        <td class="activity-ip">{{ entry.ip_address ?? '' }}</td>
                    </tr>
                </tbody>
            </table></div>
            <div v-if="pagination.last > 1" class="activity-pages">
                <button class="secondary-button" type="button" :disabled="pagination.current <= 1" @click="go({ page: pagination.current - 1 })">Nieuwer</button>
                <span class="transcription-stage">Pagina {{ pagination.current }} van {{ pagination.last }} · {{ pagination.total }} regels</span>
                <button class="secondary-button" type="button" :disabled="pagination.current >= pagination.last" @click="go({ page: pagination.current + 1 })">Ouder</button>
            </div>
        </div>
    </AppLayout>
</template>
