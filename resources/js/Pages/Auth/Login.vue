<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({ username: '', password: '', remember: true });
const submit = () => form.post('/login', { onFinish: () => form.reset('password') });
</script>

<template>
    <Head title="Inloggen" />
    <main class="login-page">
        <section class="login-card">
            <div class="sidebar-brand login-brand">
                <div class="brand-mark">C</div>
                <div><span class="brand-name">Creator<span>SMP4</span></span><span class="brand-subtitle">Analyseomgeving</span></div>
            </div>
            <h1>Inloggen</h1>
            <form @submit.prevent="submit">
                <div class="form-field"><label for="login-username">Gebruikersnaam</label><input id="login-username" v-model="form.username" type="text" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus /><p v-if="form.errors.username" class="form-error">{{ form.errors.username }}</p></div>
                <div class="form-field"><label for="login-password">Wachtwoord</label><input id="login-password" v-model="form.password" type="password" autocomplete="current-password" required /><p v-if="form.errors.password" class="form-error">{{ form.errors.password }}</p></div>
                <label class="login-remember"><input v-model="form.remember" type="checkbox" /> Ingelogd blijven</label>
                <button class="primary-button login-submit" type="submit" :disabled="form.processing">{{ form.processing ? 'Inloggen…' : 'Inloggen' }}</button>
            </form>
        </section>
    </main>
</template>
