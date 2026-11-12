<script setup>
import { onMounted, ref, watch } from 'vue';
import { useTheme } from 'vuetify';
import { activeTheme } from './lib/theme';
import { http } from './lib/http';
import LoginView from './views/LoginView.vue';
import PublicHome from './views/PublicHome.vue';
import AcePopupLayer from './components/AcePopupLayer.vue';
import AceShell from './views/AceShell.vue';
import DevConsole from './views/DevConsole.vue';
import SignupPage from './views/SignupPage.vue';
import PaymentStatusPage from './views/PaymentStatusPage.vue';
import LegalPage from './views/LegalPage.vue';
import VerifyEmailPage from './views/VerifyEmailPage.vue';
import BillingAccessPage from './views/BillingAccessPage.vue';
import '../css/ace.css';
import '../css/theme.css';
import '../css/saas.css';
import '../css/ace-dev.css';
import '../css/ace-billing.css';
import '../css/ace-dev-unified.css';
import '../css/ace-account.css';
import '../css/ace-sponsors.css';
import '../css/ace-popups.css';
import '../css/ace-ads.css';
import '../css/ace-dev-ads.css';
import '../css/ace-support.css';

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
async function refreshProfileSession() {
    try { const {data}=await http.get('/auth/me'); session.value=data.data; }
    catch(e){sessionError.value='Não foi possível atualizar os dados da sessão.';}
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
const path = window.location.pathname;
const publicHome = path === '/';
const standalonePage = ['/','/contratar','/pagamento','/privacidade','/termos','/verificar-email'].includes(path);
onMounted(() => { if (standalonePage) loading.value=false; else refreshSession(); });
function authenticated(data) { session.value=data; history.replaceState({}, '', data.role === 'dev' ? '/dev' : '/dashboard'); }
</script>

<template>
    <v-app>
        <PublicHome v-if="publicHome" />
        <SignupPage v-else-if="path === '/contratar'" />
        <PaymentStatusPage v-else-if="path === '/pagamento'" />
        <LegalPage v-else-if="['/privacidade','/termos'].includes(path)" />
        <VerifyEmailPage v-else-if="path === '/verificar-email'" />
        <div v-else-if="loading" class="app-loading" role="status">
            <div class="loading-brand">A</div>
            <v-progress-circular indeterminate color="primary" :size="27" />
            <p>Preparando seu workspace...</p>
        </div>
        <DevConsole v-else-if="session?.role === 'dev'" :session="session" @logout="logout" @refresh-session="refreshProfileSession" />
        <BillingAccessPage v-else-if="session?.tenant?.billing_blocked" :session="session" @logout="logout" @refresh-session="refreshProfileSession" @refresh="refreshSession" />
        <AceShell v-else-if="session" :session="session" @logout="logout" @refresh-session="refreshProfileSession" />
        <LoginView v-else :initial-error="sessionError" @authenticated="authenticated" />
        <AcePopupLayer v-if="publicHome" placement="home" />
        <AcePopupLayer v-if="path === '/contratar'" placement="signup" />
        <AcePopupLayer v-if="!standalonePage && !loading && !session" placement="login" />
    </v-app>
</template>
