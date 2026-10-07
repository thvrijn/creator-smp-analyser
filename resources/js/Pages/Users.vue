<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import FlashMessages from '../Components/FlashMessages.vue';
import AppLayout from '../Layouts/AppLayout.vue';
import { formatDate } from '../types/streams';

type Account = { id: number; username: string; name: string; is_admin: boolean; created_at: string | null };
defineProps<{ users: Account[] }>();

const form = useForm({ username: '', name: '', password: '' });
const submit = () => form.post('/users', { preserveScroll: true, onSuccess: () => form.reset() });
const remove = (account: Account) => {
    if (window.confirm('Account "' + account.username + '" verwijderen? Die persoon kan daarna niet meer inloggen.')) {
        router.delete('/users/' + account.id, { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="Gebruikers" />
    <AppLayout title="Gebruikers" eyebrow="Systeem">
        <FlashMessages />
        <div class="profile-grid users-grid">
            <section class="standalone-panel">
                <div class="panel-heading"><div><h3>Accounts</h3><p class="muted-copy">Iedereen hier kan inloggen. Zelf een account aanmaken kan niet.</p></div></div>
                <div class="streams-table-wrap"><table class="streams-table users-table">
                    <thead><tr><th>Gebruikersnaam</th><th>Naam</th><th>Aangemaakt</th><th><span class="sr-only">Acties</span></th></tr></thead>
                    <tbody>
                        <tr v-for="account in users" :key="account.id">
                            <td><strong>{{ account.username }}</strong> <span v-if="account.is_admin" class="status-badge status-finished">Admin</span></td>
                            <td>{{ account.name }}</td>
                            <td>{{ formatDate(account.created_at) }}</td>
                            <td class="action-cell"><button v-if="!account.is_admin" class="delete-button" type="button" @click="remove(account)">Verwijderen</button></td>
                        </tr>
                    </tbody>
                </table></div>
            </section>
            <section class="standalone-panel">
                <div class="panel-heading"><div><h3>Account aanmaken</h3><p class="muted-copy">Geef de gebruikersnaam en het wachtwoord zelf door. De persoon kan het wachtwoord daarna wijzigen bij Profiel.</p></div></div>
                <form autocomplete="off" @submit.prevent="submit">
                    <div class="form-field"><label for="user-username">Gebruikersnaam</label><input id="user-username" v-model="form.username" type="text" autocapitalize="none" spellcheck="false" placeholder="bijv. sophie" required /><p v-if="form.errors.username" class="form-error">{{ form.errors.username }}</p></div>
                    <div class="form-field"><label for="user-name">Naam</label><input id="user-name" v-model="form.name" type="text" placeholder="Sophie" required /><p v-if="form.errors.name" class="form-error">{{ form.errors.name }}</p></div>
                    <div class="form-field"><label for="user-password">Wachtwoord <span>(minstens 8 tekens)</span></label><input id="user-password" v-model="form.password" type="password" autocomplete="new-password" minlength="8" required /><p v-if="form.errors.password" class="form-error">{{ form.errors.password }}</p></div>
                    <div class="modal-actions"><button class="primary-button" type="submit" :disabled="form.processing">{{ form.processing ? 'Aanmaken…' : 'Account aanmaken' }}</button></div>
                </form>
            </section>
        </div>
    </AppLayout>
</template>
