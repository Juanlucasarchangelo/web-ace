<script setup>
import { onMounted, ref, watch } from 'vue';
import { useTheme } from 'vuetify';
import { activeTheme } from './lib/theme';
import { http } from './lib/http';
import LoginView from './views/LoginView.vue';
import PublicHome from './views/PublicHome.vue';
import AceShell from './views/AceShell.vue';
import '../css/ace.css';
import '../css/theme.css';
import '../css/saas.css';

const vuetifyTheme = useTheme();
watch(activeTheme, mode => { vuetifyTheme.global.name.value = mode === 'dark' ? 'aceDark' : 'aceLight'; }, { immediate: true });

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
const publicHome = window.location.pathname === '/';
onMounted(() => { if (publicHome) loading.value=false; else refreshSession(); });
function authenticated(data) { session.value=data; history.replaceState({}, '', '/dashboard'); }
</script>

<template>
    <v-app>
        <PublicHome v-if="publicHome" />
        <div v-else-if="loading" class="app-loading" role="status">
            <div class="loading-brand">A</div>
            <v-progress-circular indeterminate color="primary" :size="27" />
            <p>Preparando seu workspace...</p>
        </div>
        <AceShell v-else-if="session" :session="session" @logout="logout" />
        <LoginView v-else :initial-error="sessionError" @authenticated="authenticated" />
    </v-app>
</template>
