<script setup>
import { onMounted, ref } from 'vue';
import { http } from './lib/http';
import LoginView from './views/LoginView.vue';
import AceShell from './views/AceShell.vue';
import '../css/ace.css';

const loading = ref(true);
const session = ref(null);
const sessionError = ref('');

async function refreshSession() {
    loading.value = true;
    try {
        const { data } = await http.get('/auth/me');
        session.value = data.data;
        sessionError.value = '';
    } catch (error) {
        session.value = null;
        sessionError.value = error.response?.status === 403
            ? 'Seu acesso à empresa está inativo.'
            : error.response?.status === 401 ? '' : 'Não foi possível consultar a sessão.';
    } finally {
        loading.value = false;
    }
}
async function logout() {
    try {
        await http.post('/auth/logout');
        session.value = null;
        sessionError.value = '';
        delete http.defaults.headers.common['X-Tenant-Id'];
    } catch (error) {
        if (error.response?.status === 401 || error.response?.status === 419) {
            location.reload();
            return;
        }
        sessionError.value = 'Não foi possível encerrar a sessão.';
    }
}
onMounted(refreshSession);
</script>

<template>
    <v-app>
        <div v-if="loading" class="app-loading" role="status">
            <div class="loading-brand">A</div>
            <v-progress-circular indeterminate color="primary" :size="27" />
            <p>Preparando seu workspace...</p>
        </div>
        <AceShell v-else-if="session" :session="session" @logout="logout" />
        <LoginView v-else :initial-error="sessionError" @authenticated="session = $event" />
    </v-app>
</template>
