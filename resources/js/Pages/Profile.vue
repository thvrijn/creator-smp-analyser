<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import FlashMessages from '../Components/FlashMessages.vue';
import AppLayout from '../Layouts/AppLayout.vue';

defineProps<{ account: { username: string; name: string; email: string | null } }>();

const form = useForm({ current_password: '', password: '', password_confirmation: '' });
const submit = () => form.put('/profile/password', {
    preserveScroll: true,
    onSuccess: () => form.reset(),
    onError: () => form.reset('current_password'),
});
</script>

<template>
    <Head title="Profiel" />
    <AppLayout title="Profiel" eyebrow="Account">
        <FlashMessages />
        <div class="profile-grid">
            <section class="standalone-panel">
                <div class="panel-heading"><div><h3>Account</h3></div></div>
                <dl class="profile-facts">
                    <dt>Gebruikersnaam</dt><dd>{{ account.username }}</dd>
                    <dt>Naam</dt><dd>{{ account.name }}</dd>
                    <dt>E-mailadres</dt><dd>{{ account.email ?? '—' }}</dd>
                </dl>
            </section>
            <section class="standalone-panel">
                <div class="panel-heading"><div><h3>Wachtwoord wijzigen</h3><p class="muted-copy">Minstens 8 tekens. Andere apparaten worden daarna uitgelogd.</p></div></div>
                <form @submit.prevent="submit">
                    <input type="text" :value="account.username" autocomplete="username" hidden readonly />
                    <div class="form-field"><label for="current-password">Huidig wachtwoord</label><input id="current-password" v-model="form.current_password" type="password" autocomplete="current-password" required /><p v-if="form.errors.current_password" class="form-error">{{ form.errors.current_password }}</p></div>
                    <div class="form-field"><label for="new-password">Nieuw wachtwoord</label><input id="new-password" v-model="form.password" type="password" autocomplete="new-password" minlength="8" required /><p v-if="form.errors.password" class="form-error">{{ form.errors.password }}</p></div>
                    <div class="form-field"><label for="confirm-password">Herhaal nieuw wachtwoord</label><input id="confirm-password" v-model="form.password_confirmation" type="password" autocomplete="new-password" minlength="8" required /></div>
                    <div class="modal-actions"><button class="primary-button" type="submit" :disabled="form.processing">{{ form.processing ? 'Opslaan…' : 'Wachtwoord wijzigen' }}</button></div>
                </form>
            </section>
        </div>
    </AppLayout>
</template>
