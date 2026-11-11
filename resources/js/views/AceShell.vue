<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { LayoutDashboard, UsersRound, Globe2, KeyRound, Building2, UserRoundCog, History, LogOut, Menu, ChevronDown, PanelLeftClose, ShieldCheck, Cloud, Search, ArrowUpRight, Sparkles, X, MessageCircle, CreditCard } from 'lucide-vue-next';
import { http } from '../lib/http';
import { serverError, roleNames } from '../lib/ace';
import DashboardPage from './DashboardPage.vue';
import ClientsPage from './ClientsPage.vue';
import SitesPage from './SitesPage.vue';
import TeamPage from './TeamPage.vue';
import CompaniesPage from './CompaniesPage.vue';
import VaultPage from './VaultPage.vue';
import ActivityPage from './ActivityPage.vue';
import CloudPage from './CloudPage.vue';
import AceAiPage from './AceAiPage.vue';
import BillingPage from './BillingPage.vue';
import AceChatPanel from '../components/AceChatPanel.vue';
import { initializeAceAi, clearAceAiSession } from '../lib/aceAiChat';
import ThemeSwitcher from '../components/ThemeSwitcher.vue';

const props = defineProps({ session: { type: Object, required: true } });
const emit = defineEmits(['logout']);
const page = ref(window.location.pathname.replace(/^\//, '') || 'dashboard');
const sidebarOpen = ref(false);
const floatingOpen = ref(false);
const floatingPanel = ref(null);
const tenantId = ref(props.session.tenant.id);
const companies = ref([]);
const companyError = ref('');
const tenantName = ref(props.session.tenant.name);
const role = ref(props.session.role);
const reloadKey = ref(0);
const selectedSiteId = ref(window.location.pathname === '/sites' ? Number(new URLSearchParams(window.location.search).get('site')) || null : null);
const nav = [
    { id: 'dashboard', label: 'Visão geral', icon: LayoutDashboard },
    { id: 'clientes', label: 'Clientes', icon: UsersRound },
    { id: 'sites', label: 'Sites', icon: Globe2 },
    { id: 'cofre', label: 'Cofre de acessos', icon: KeyRound },
    { id: 'ia', label: 'ACE IA', icon: Search },
    { id: 'empresas', label: 'Empresas', icon: Building2 },
    { id: 'equipe', label: 'Usuários e permissões', icon: UserRoundCog },
    { id: 'atividade', label: 'Auditoria', icon: History },
    { id: 'nuvem', label: 'Integrações em nuvem', icon: Cloud },
    { id: 'assinatura', label: 'Plano e pagamento', icon: CreditCard },
];
const names = Object.fromEntries(nav.map(item => [item.id, item.label]));
const canEdit = computed(() => ['owner', 'admin'].includes(role.value));

function navigate(where, siteId = null) {
    selectedSiteId.value = siteId;
    page.value = where;
    history.pushState({}, '', '/' + where + (where === 'sites' && siteId ? '?site=' + encodeURIComponent(siteId) : ''));
    sidebarOpen.value = false;
}
window.addEventListener('popstate', () => {
    page.value = window.location.pathname.replace(/^\//, '') || 'dashboard';
    selectedSiteId.value = page.value === 'sites' ? Number(new URLSearchParams(window.location.search).get('site')) || null : null;
});
watch(tenantId, async (id) => {
    http.defaults.headers.common['X-Tenant-Id'] = id;
    const company = companies.value.find(t => t.id === id);
    if (company) { tenantName.value = company.name; role.value = company.role; }
    reloadKey.value += 1;
    floatingOpen.value = false;
    initializeAceAi(id, props.session.user.id);
});
async function loadWorkspaces() {
    try {
        const { data } = await http.get('/workspaces');
        companies.value = data.data;
        const selected = companies.value.find(t => t.id === tenantId.value);
        if (selected) { tenantName.value = selected.name; role.value = selected.role; }
    } catch (e) { companyError.value = serverError(e); }
}
function refreshWorkspace() { loadWorkspaces(); reloadKey.value += 1; }
function openFloating() { floatingOpen.value = true; nextTickFocus(); }
function nextTickFocus() { nextTick(() => floatingPanel.value?.focusInput()); }
function logoutAce() { clearAceAiSession(); emit('logout'); }
onMounted(async () => {
    http.defaults.headers.common['X-Tenant-Id'] = tenantId.value;
    initializeAceAi(tenantId.value, props.session.user.id);
    await loadWorkspaces();
});
</script>

<template>
    <div class="ace-shell">
        <div v-if="sidebarOpen" class="ace-sidebar-scrim" @click="sidebarOpen = false"></div>
        <aside class="ace-sidebar" :class="{ 'is-open': sidebarOpen }">
            <div class="ace-sidebar-top">
                <div class="ace-brand"><span class="ace-logo"><KeyRound :size="21" :stroke-width="2.3" /></span><span>ace<span class="ace-brand-dot">.</span></span></div>
                <button class="ace-side-collapse" aria-label="Fechar menu" @click="sidebarOpen = false"><PanelLeftClose :size="18" /></button>
            </div>
            <div class="ace-space-label">WORKSPACE</div>
            <div class="ace-company-chooser">
                <div class="ace-company-mark"><Building2 :size="19" /></div>
                <div class="ace-company-meta"><strong>{{ tenantName }}</strong><small>{{ roleNames[role] || role }}</small></div>
                <ChevronDown :size="15" class="ace-company-chevron" />
                <select v-model="tenantId" aria-label="Selecionar empresa" class="ace-company-select">
                    <option v-for="t in companies" :key="t.id" :value="t.id">{{ t.name }}</option>
                </select>
            </div>
            <div class="ace-side-section">MENU PRINCIPAL</div>
            <nav class="ace-side-nav" aria-label="Menu principal">
                <button v-for="item in nav.slice(0, 5)" :key="item.id" class="ace-nav-link" :class="{ active: page === item.id }" @click="navigate(item.id)">
                    <component :is="item.icon" :size="18" :stroke-width="1.9" /><span>{{ item.label }}</span><span v-if="page === item.id" class="ace-nav-active-marker"></span>
                </button>
                <div class="ace-side-section ace-secondary-title">ADMINISTRAÇÃO</div>
                <button v-for="item in nav.slice(5)" :key="item.id" class="ace-nav-link" :class="{ active: page === item.id }" @click="navigate(item.id)">
                    <component :is="item.icon" :size="18" :stroke-width="1.9" /><span>{{ item.label }}</span><span v-if="page === item.id" class="ace-nav-active-marker"></span>
                </button>
            </nav>
            <div class="ace-sidebar-bottom">
                <button class="ace-cloud-note ace-cloud-shortcut" @click="navigate('nuvem')"><div class="ace-cloud-icon"><Cloud :size="19" /></div><strong>ACE Cloud</strong><p>Google Drive, arquivos e pastas no seu workspace.</p><div>Explorar integrações <ArrowUpRight :size="13" /></div></button>
                <button class="ace-user-bottom" @click="logoutAce"><span class="ace-user-letter">{{ session.user.name.slice(0, 1).toUpperCase() }}</span><span class="ace-user-details"><strong>{{ session.user.name }}</strong><small>{{ session.user.email }}</small></span><LogOut :size="18" /></button>
            </div>
        </aside>
        <div class="ace-main-container">
            <header class="ace-topbar">
                <div class="ace-topbar-left"><button class="ace-mobile-menu" aria-label="Abrir menu" @click="sidebarOpen = true"><Menu :size="22" /></button><span class="ace-crumb">Workspace</span><span class="ace-crumb-divider">/</span><strong>{{ names[page] || 'Visão geral' }}</strong></div>
                <div class="ace-topbar-right"><ThemeSwitcher /><span class="ace-security-chip"><ShieldCheck :size="14" /> Ambiente protegido</span><span class="ace-topbar-avatar">{{ session.user.name.slice(0, 1).toUpperCase() }}</span></div>
            </header>
            <main class="ace-main-content">
                <div v-if="companyError" class="ace-error">{{ companyError }}</div>
                <DashboardPage v-if="page === 'dashboard'" :key="'d'+reloadKey" :tenant-name="tenantName" @navigate="navigate" />
                <ClientsPage v-else-if="page === 'clientes'" :key="'c'+reloadKey" :can-edit="canEdit" @navigate="navigate" />
                <SitesPage v-else-if="page === 'sites'" :key="'s'+reloadKey" :can-edit="canEdit" :initial-site-id="selectedSiteId" />
                <VaultPage v-else-if="page === 'cofre'" :key="'v'+reloadKey" :can-edit="canEdit" @navigate="navigate" />
                <CompaniesPage v-else-if="page === 'empresas'" :key="'w'+reloadKey" :tenant-id="tenantId" :tenant-name="tenantName" :role="role" :companies="companies" @created="refreshWorkspace" @change="tenantId = $event" />
                <TeamPage v-else-if="page === 'equipe'" :key="'t'+reloadKey" :can-edit="canEdit" />
                <ActivityPage v-else-if="page === 'atividade'" :key="'a'+reloadKey" />
                <BillingPage v-else-if="page === 'assinatura'" :key="'billing'+reloadKey" />
                <CloudPage v-else-if="page === 'nuvem'" :key="'cloud'+reloadKey" />
                <AceAiPage v-else-if="page === 'ia'" :key="'ai'+reloadKey" :page="page" @navigate="navigate" @updated="reloadKey += 1" />
                <DashboardPage v-else :tenant-name="tenantName" @navigate="navigate" />
            </main>
            <footer class="ace-footer">© ACE · Gestão inteligente de acessos empresariais <span>ACE · Laravel</span></footer>
        </div>
        <div class="ace-floating-area" :class="{ 'is-open': floatingOpen }">
            <div v-if="floatingOpen" class="ace-floating-panel-wrap" role="dialog" aria-label="Assistente flutuante ACE IA">
                <AceChatPanel ref="floatingPanel" mode="floating" :page="page" :site-id="selectedSiteId" @navigate="navigate" @updated="reloadKey += 1" @close="floatingOpen = false" />
            </div>
            <button class="ace-floating-trigger" type="button" :aria-label="floatingOpen ? 'Recolher ACE IA' : 'Abrir ACE IA'" :aria-expanded="floatingOpen" @click="floatingOpen ? floatingOpen = false : openFloating()">
                <X v-if="floatingOpen" :size="24"/><Sparkles v-else :size="25"/>
                <span v-if="!floatingOpen">Pergunte à ACE IA</span>
            </button>
        </div>
    </div>
</template>
