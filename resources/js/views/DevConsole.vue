<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import { Activity, AlertTriangle, Building2, CheckCircle2, Cloud, KeyRound, LogOut, RefreshCw, Search, ShieldCheck, UsersRound, XCircle, History, CreditCard, TicketPercent, Headset, BellRing, Sparkles, X, UserRoundCog, Megaphone, ChevronDown, Image, Layers, PanelsTopLeft } from 'lucide-vue-next';
import { http } from '../lib/http';
import { serverError } from '../lib/ace';
import ThemeSwitcher from '../components/ThemeSwitcher.vue';
import ProfilePage from './ProfilePage.vue';
import DevPlansManager from '../components/DevPlansManager.vue';
import DevAffiliatesManager from '../components/DevAffiliatesManager.vue';
import DevProvisionManager from '../components/DevProvisionManager.vue';
import DevSponsorsManager from '../components/DevSponsorsManager.vue';
import DevPopupsManager from '../components/DevPopupsManager.vue';
import DevAdsManager from '../components/DevAdsManager.vue';
import SupportTickets from '../components/SupportTickets.vue';
import DevMessagingManager from '../components/DevMessagingManager.vue';
import AceChatPanel from '../components/AceChatPanel.vue';
import { initializeDevAi, clearAceAiSession } from '../lib/aceAiChat';

const props = defineProps({ session: { type: Object, required: true } });
const emit = defineEmits(['logout','refresh-session']);
const profileDialog = ref(false);
const tab = ref('tenants');
const adsExpanded = ref(false);
const advertisingTabs = ['sponsors', 'popups', 'ads'];
const independentTabs = [...advertisingTabs,'support','messaging'];
const advertisingTitles = { sponsors:'Patrocinadores', popups:'Popups', ads:'Anúncios' };
const floatingOpen = ref(false);
const floatingPanel = ref(null);
function logoutDev() { clearAceAiSession(); emit('logout'); }
function openFloating() { floatingOpen.value = true; nextTick(() => floatingPanel.value?.focusInput()); }
const summary = ref(null);
const tenants = ref([]);
const planLabels = ref({});
async function loadPlanLabels() { try { const {data} = await http.get('/dev/plans'); planLabels.value = Object.fromEntries((data.data.plans || []).map(p => [p.id, p.name])); } catch { /* Fallback to technical keys. */ } }
const users = ref([]);
const issues = ref([]);
const auditRecords = ref([]);
const search = ref('');
const issueState = ref('open');
const tenantPage = ref(1);
const tenantLast = ref(1);
const userPage = ref(1);
const userLast = ref(1);
const issuePage = ref(1);
const issueLast = ref(1);
const auditPage = ref(1);
const auditLast = ref(1);
const details = ref(null);
const selected = ref(null);
const seats = ref(1);
const plan = ref('free');
const billing = ref('unverified');
const busy = ref(false);
const loading = ref(false);
const error = ref('');
const notice = ref('');
const tabs = [
  { id: 'tenants', label: 'Empresas', icon: Building2 },
  { id: 'provision', label: 'Criar empresas e usuários', icon: UsersRound },
  { id: 'users', label: 'Todos os usuários', icon: UsersRound },
  { id: 'issues', label: 'Problemas e logs', icon: AlertTriangle },
  { id: 'audit', label: 'Ações do DEV', icon: History },
  { id: 'plans', label: 'Planos e preços', icon: CreditCard },
  { id: 'affiliates', label: 'Afiliados e cupons', icon: TicketPercent },
  { id: 'support', label: 'Chamados', icon: Headset },
  { id: 'messaging', label: 'Mensageria', icon: BellRing },
];
const statusLabels = { connected: 'Conectado', error: 'Erro de conexão', reconnect_required: 'Reconexão necessária', unknown: 'Não verificado', not_connected: 'Sem conexão' };
const billingLabels = { unverified: 'Não verificado', pending: 'Pendente', paid: 'Pago', overdue: 'Atrasado', canceled: 'Cancelado (manual)' };
const fmtDate = value => value ? new Date(value).toLocaleString('pt-BR') : '—';
const isProblem = value => ['error', 'reconnect_required', 'not_connected'].includes(value);
const hasResults = computed(() => tab.value === 'tenants' ? tenants.value.length : tab.value === 'users' ? users.value.length : tab.value === 'issues' ? issues.value.length : auditRecords.value.length);
function failed(e) { error.value = serverError(e); notice.value = ''; }
function alertNotice(message) { notice.value = message; error.value = ''; }
async function loadSummary() {
  try { summary.value = (await http.get('/dev/overview')).data.data; } catch (e) { failed(e); }
}
async function loadTable() {
  loading.value = true; error.value = '';
  try {
    if (tab.value === 'tenants') {
      const {data} = await http.get('/dev/tenants', {params: {page: tenantPage.value, search: search.value || undefined}});
      tenants.value = data.data.data; tenantLast.value = data.data.last_page;
    } else if (tab.value === 'users') {
      const {data} = await http.get('/dev/users', {params: {page: userPage.value, search: search.value || undefined}});
      users.value = data.data.data; userLast.value = data.data.last_page;
    } else if (tab.value === 'issues') {
      const {data} = await http.get('/dev/issues', {params: {page: issuePage.value, state: issueState.value}});
      issues.value = data.data.data; issueLast.value = data.data.last_page;
    } else {
      const {data} = await http.get('/dev/audit', {params: {page: auditPage.value}});
      auditRecords.value = data.data.data; auditLast.value = data.data.last_page;
    }
  } catch(e) { failed(e); }
  finally { loading.value = false; }
}
function chooseTab(next) {
  tab.value = next; search.value = ''; details.value = null; selected.value = null; error.value = ''; notice.value = '';
  tenantPage.value = 1; userPage.value = 1; issuePage.value = 1; auditPage.value = 1;
  if (!['plans','affiliates','provision',...independentTabs].includes(next)) loadTable();
}
function changePage(delta) {
  if (tab.value === 'tenants') tenantPage.value = Math.max(1, Math.min(tenantLast.value, tenantPage.value + delta));
  if (tab.value === 'users') userPage.value = Math.max(1, Math.min(userLast.value, userPage.value + delta));
  if (tab.value === 'issues') issuePage.value = Math.max(1, Math.min(issueLast.value, issuePage.value + delta));
  if (tab.value === 'audit') auditPage.value = Math.max(1, Math.min(auditLast.value, auditPage.value + delta));
  loadTable();
}
const currentPage = computed(() => tab.value === 'tenants' ? tenantPage.value : tab.value === 'users' ? userPage.value : tab.value === 'issues' ? issuePage.value : auditPage.value);
const lastPage = computed(() => tab.value === 'tenants' ? tenantLast.value : tab.value === 'users' ? userLast.value : tab.value === 'issues' ? issueLast.value : auditLast.value);
async function openTenant(tenant) {
  selected.value = tenant; seats.value = tenant.seat_limit;
  plan.value = tenant.plan; billing.value = tenant.billing_status;
  details.value = {tenant: {id:tenant.id, name:tenant.name}, accounts: []};
  await refreshConnections();
}
async function refreshConnections() {
  if (!selected.value) return;
  try { details.value = (await http.get(`/dev/tenants/${selected.value.id}/connections`)).data.data; }
  catch(e) { failed(e); }
}
async function checkDrive(conn) {
  if (busy.value || !selected.value) return;
  busy.value = true; error.value = '';
  try {
    const {data} = await http.post(`/dev/tenants/${selected.value.id}/connections/${conn.id}/check`, {}, {timeout:30000});
    alertNotice(data.data.message);
    await Promise.all([refreshConnections(), loadSummary(), loadTable()]);
  } catch(e) { failed(e); }
  finally { busy.value = false; }
}
async function saveSeats() {
  if (!selected.value || busy.value) return;
  if (!Number.isInteger(Number(seats.value)) || Number(seats.value) < 1) { error.value = 'Informe um limite válido.'; return; }
  if (!window.confirm(`Alterar o limite da equipe ${selected.value.name} para ${seats.value} usuário(s)?`)) return;
  busy.value = true;
  try {
    await http.patch(`/dev/tenants/${selected.value.id}/seats`, {seat_limit: Number(seats.value)});
    selected.value.seat_limit = Number(seats.value);
    alertNotice('Limite de usuários atualizado pelo DEV.');
    await loadTable();
  } catch(e) { failed(e); }
  finally { busy.value = false; }
}
async function saveBilling() {
  if (!selected.value || busy.value) return;
  if (selected.value.billing_source === 'mercadopago') { error.value='Cobranças do Mercado Pago não podem ser alteradas manualmente.'; return; }
  if (!window.confirm(`Atualizar manualmente plano e status de cobrança de ${selected.value.name}? Esta informação não é validada com um gateway de pagamentos.`)) return;
  busy.value = true;
  try {
    await http.patch(`/dev/tenants/${selected.value.id}/billing`, {plan:plan.value, billing_status:billing.value});
    selected.value.plan = plan.value; selected.value.billing_status = billing.value;
    alertNotice('Situação de cobrança registrada MANUALMENTE.');
    await Promise.all([loadTable(), loadSummary()]);
  } catch(e) { failed(e); }
  finally { busy.value = false; }
}
async function resolveIssue(issue) {
  if (busy.value || !window.confirm('Marcar esta ocorrência como resolvida? O registro permanecerá no histórico.')) return;
  busy.value = true;
  try {
    await http.post(`/dev/issues/${issue.id}/resolve`);
    alertNotice('Ocorrência resolvida e mantida no histórico.');
    await Promise.all([loadSummary(), loadTable()]);
  } catch(e) { failed(e); }
  finally { busy.value = false; }
}
async function refreshAll() { await Promise.all([loadSummary(), ...(['plans','affiliates','provision',...independentTabs].includes(tab.value) ? [] : [loadTable()]), ...(selected.value ? [refreshConnections()] : [])]); }
function resetSearch() { tenantPage.value = 1; userPage.value = 1; loadTable(); }
onMounted(async () => { delete http.defaults.headers.common['X-Tenant-Id']; initializeDevAi(props.session.user.id); await Promise.all([refreshAll(), loadPlanLabels()]); });
</script>

<template>
  <div class="dev-shell ace-shell dev-unified">
    <aside class="dev-sidebar ace-sidebar">
      <div class="dev-brand ace-brand"><span class="dev-brand-icon ace-logo"><ShieldCheck :size="22"/></span><div><strong>ace<span style="color:#ffc734">.</span> DEV</strong><small>CONTROLE OPERACIONAL</small></div></div>
      <div class="dev-sidebar-label">VISÃO GLOBAL</div>
      <nav class="dev-sidebar-nav ace-side-nav" aria-label="Navegação DEV">
        <button v-for="item in tabs" :key="item.id" type="button" class="ace-nav-link" :class="{active:tab===item.id}" @click="chooseTab(item.id)"><component :is="item.icon" :size="18"/>{{item.label}}</button>
      </nav>
      <div class="dev-sidebar-label dev-ads-section-label">ADMINISTRAÇÃO</div>
      <nav class="dev-sidebar-nav ace-side-nav dev-advertising-nav" aria-label="Administração de anúncios">
        <button type="button" class="ace-nav-link dev-ads-parent" :class="{active:advertisingTabs.includes(tab)}" :aria-expanded="adsExpanded" @click="adsExpanded=!adsExpanded"><Megaphone :size="18"/> <span>Anúncios</span><ChevronDown :size="16" :class="{'is-open':adsExpanded}"/></button>
        <div v-if="adsExpanded" class="dev-ads-submenu">
          <button type="button" class="ace-nav-link" :class="{active:tab==='sponsors'}" @click="chooseTab('sponsors')"><Image :size="16"/> Patrocinadores</button>
          <button type="button" class="ace-nav-link" :class="{active:tab==='popups'}" @click="chooseTab('popups')"><PanelsTopLeft :size="16"/> Popups</button>
          <button type="button" class="ace-nav-link" :class="{active:tab==='ads'}" @click="chooseTab('ads')"><Layers :size="16"/> Anúncios</button>
        </div>
      </nav>
      <div class="dev-sidebar-foot"><ShieldCheck :size="17"/> Visibilidade de metadados apenas. Dados de clientes e arquivos permanecem isolados.</div>
      <button class="account-sidebar-profile" type="button" @click="profileDialog=true"><UserRoundCog :size="17"/> Meu perfil</button>
      <button class="dev-logout" type="button" @click="logoutDev"><LogOut :size="17"/> Sair da conta DEV</button>
    </aside>
    <div class="dev-main ace-main-container">
      <header class="dev-topbar ace-topbar"><div><span>ACE / Administração global</span><strong>{{ advertisingTitles[tab] || tabs.find(item=>item.id===tab)?.label || 'Central DEV' }}</strong></div><div class="dev-profile"><ThemeSwitcher/><button class="account-dev-profile-btn" type="button" @click="profileDialog=true"><UserRoundCog :size="16"/> {{session.user.email}}</button><span class="dev-profile-badge">DEV</span></div></header>
      <main class="dev-content ace-main-content">
        <div class="dev-page-title"><div><div class="dev-overline"><Activity :size="15"/> {{ advertisingTabs.includes(tab) ? 'ADMINISTRAÇÃO / ANÚNCIOS' : tab === 'support' ? 'ATENDIMENTO' : tab === 'messaging' ? 'COMUNICAÇÃO' : 'ADMINISTRAÇÃO DEV' }}</div><h1>{{tab==='plans'?'Gestão de planos':tab==='affiliates'?'Gestão de afiliados':tab==='provision'?'Criar empresas e usuários':advertisingTabs.includes(tab)?advertisingTitles[tab]:tab==='support'?'Chamados de suporte':tab==='messaging'?'Mensageria':'Central de controle'}}<span>.</span></h1><p>{{tab==='plans'?'Gerencie preços e conteúdo comercial sem editar código.':advertisingTabs.includes(tab)?'Gerencie campanhas, patrocinadores e comunicações em páginas do ACE.':tab==='support'?'Acompanhe e responda aos chamados enviados pelos clientes.':tab==='messaging'?'Configure o Resend e acompanhe os envios transacionais.':'Supervisão das empresas, contas e integrações — sem acesso aos dados internos.'}}</p></div><button type="button" class="dev-button-outline" :disabled="loading || busy" @click="refreshAll"><RefreshCw :size="16"/> Atualizar</button></div>
        <div v-if="error" class="dev-banner dev-error" role="alert">{{error}}</div>
        <div v-if="notice" class="dev-banner dev-success" role="status">{{notice}}</div>
        <section v-if="!independentTabs.includes(tab)" class="dev-metrics" aria-label="Resumo global">
          <div class="dev-metric"><span>Empresas ativas</span><strong>{{summary?.active_tenants ?? '—'}}</strong><small>{{summary?.tenants ?? '—'}} empresas cadastradas</small></div>
          <div class="dev-metric"><span>Usuários no sistema</span><strong>{{summary?.users ?? '—'}}</strong><small>Identidades cadastradas</small></div>
          <div class="dev-metric"><span>Conexões Google Drive</span><strong>{{summary?.drive_connections ?? '—'}}</strong><small>{{summary?.tenants_without_drive ?? '—'}} empresas sem conexão</small></div>
          <div class="dev-metric dev-metric-risk"><span>Problemas não resolvidos</span><strong>{{summary?.open_issues ?? '—'}}</strong><small>{{summary?.drive_reconnect_required ?? '—'}} conta(s) requerem reconexão</small></div>
          <div class="dev-metric"><span>Faturas não verificadas</span><strong>{{summary?.billing_unverified ?? '—'}}</strong><small>Controle manual, sem gateway</small></div>
        </section>
        <SupportTickets v-if="tab==='support'" mode="dev" />
        <DevMessagingManager v-else-if="tab==='messaging'" />
        <DevSponsorsManager v-else-if="tab==='sponsors'" />
        <DevPopupsManager v-else-if="tab==='popups'" />
        <DevAdsManager v-else-if="tab==='ads'" />
        <DevPlansManager v-else-if="tab==='plans'" @updated="loadPlanLabels" />
        <DevAffiliatesManager v-else-if="tab==='affiliates'" />
        <DevProvisionManager v-else-if="tab==='provision'" />
        <section v-else class="dev-panel">
          <div class="dev-panel-header"><h2>{{tab==='tenants'?'Empresas e licenças':tab==='users'?'Usuários cadastrados':tab==='issues'?'Histórico de problemas':'Ações administrativas do DEV'}}</h2><div class="dev-search" v-if="['tenants','users'].includes(tab)"><Search :size="16"/><input v-model="search" aria-label="Buscar por nome ou e-mail" placeholder="Buscar..." @keyup.enter="resetSearch"/><button @click="resetSearch">Buscar</button></div><select v-else-if="tab==='issues'" v-model="issueState" class="dev-select" aria-label="Estado dos problemas" @change="issuePage=1;loadTable()"><option value="open">Não resolvidos</option><option value="resolved">Resolvidos</option><option value="all">Todos os eventos</option></select></div>
          <div class="dev-table-scroll"><table class="dev-table" v-if="tab==='tenants'"><thead><tr><th>EMPRESA</th><th>PLANO / COBRANÇA</th><th>VAGAS</th><th>GOOGLE DRIVE</th><th></th></tr></thead><tbody>
            <tr v-for="tenant in tenants" :key="tenant.id"><td><strong>{{tenant.name}}</strong><small>{{tenant.slug}} · {{tenant.is_active?'Ativa':'Inativa'}}</small></td><td><strong>{{planLabels[tenant.plan] || tenant.plan?.toUpperCase()}}</strong><small>{{tenant.billing_source==='mercadopago'?'Mercado Pago · ':'Manual · '}}{{billingLabels[tenant.billing_status]||tenant.billing_status}}</small><small v-if="tenant.billing_expires_at">Válido até {{fmtDate(tenant.billing_expires_at)}}</small></td><td><strong>{{tenant.members}} / {{tenant.seat_limit}}</strong><small>contas na equipe</small></td><td><span class="dev-pill" :class="isProblem(tenant.drive_status)?'problem':'ok'">{{statusLabels[tenant.drive_status]}}</span><small>{{tenant.drive_count}} conexão(ões)</small></td><td><button class="dev-button-outline" @click="openTenant(tenant)">Administrar</button></td></tr>
          </tbody></table>
          <table class="dev-table" v-else-if="tab==='users'"><thead><tr><th>USUÁRIO</th><th>E-MAIL</th><th>EQUIPES / PAPEL</th><th>STATUS</th></tr></thead><tbody><tr v-for="user in users" :key="user.id"><td><strong>{{user.name}}</strong><small>#{{user.id}}</small></td><td>{{user.email}}</td><td><span v-for="workspace in user.workspaces" :key="workspace.id" class="dev-workspace">{{workspace.name}} · {{workspace.role==='owner'?'Master':workspace.role}}</span><span v-if="user.is_dev" class="dev-pill">DEV global · sem acesso a dados</span><span v-else-if="!user.workspaces.length">Sem empresa</span></td><td><span class="dev-pill" :class="user.is_active?'ok':'problem'">{{user.is_active?'Ativo':'Inativo'}}</span></td></tr></tbody></table>
          <table class="dev-table" v-else-if="tab==='issues'"><thead><tr><th>QUANDO</th><th>EMPRESA</th><th>ORIGEM</th><th>PROBLEMA</th><th>ESTADO</th></tr></thead><tbody><tr v-for="issue in issues" :key="issue.id"><td>{{fmtDate(issue.created_at)}}</td><td>{{issue.tenant?.name||'Sem identificação'}}</td><td>{{issue.source}}</td><td><strong>{{issue.summary}}</strong><small>Código: {{issue.code}}</small></td><td><span class="dev-pill" :class="issue.resolved_at?'ok':'problem'">{{issue.resolved_at?'Resolvido':'Em aberto'}}</span><button v-if="!issue.resolved_at" type="button" class="dev-button-outline" :disabled="busy" @click="resolveIssue(issue)">Resolver</button></td></tr></tbody></table>
          <table class="dev-table" v-else><thead><tr><th>QUANDO</th><th>OPERADOR</th><th>EMPRESA</th><th>AÇÃO</th></tr></thead><tbody><tr v-for="item in auditRecords" :key="item.id"><td>{{fmtDate(item.created_at)}}</td><td>{{item.actor||'Conta removida'}}</td><td>{{item.tenant||'Global'}}</td><td><strong>{{item.action}}</strong></td></tr></tbody></table>
          <p v-if="!hasResults&&!loading" class="dev-empty">Nenhum registro encontrado.</p><p v-if="loading" class="dev-empty">Carregando registros...</p></div>
          <div class="dev-pagination"><span>Página {{currentPage}} de {{lastPage}}</span><div><button :disabled="currentPage<=1||loading" @click="changePage(-1)">Anterior</button><button :disabled="currentPage>=lastPage||loading" @click="changePage(1)">Próxima</button></div></div>
        </section>
        <p class="dev-privacy"><ShieldCheck :size="16"/> O DEV consulta metadados globais, nunca clientes, sites, cofre, senhas ou conteúdo do Drive. Cobranças automatizadas são verificadas pelo Mercado Pago; empresas legadas mantêm controle manual.</p>
      </main>
    </div>
    <div class="ace-floating-area" :class="{ 'is-open': floatingOpen }" v-if="!selected">
      <div v-if="floatingOpen" class="ace-floating-panel-wrap" role="dialog" aria-label="Assistente DEV">
        <AceChatPanel ref="floatingPanel" mode="floating" :dev="true" :page="'dev-'+tab" @close="floatingOpen=false" />
      </div>
      <button class="ace-floating-trigger" type="button" :aria-label="floatingOpen?'Recolher ACE IA':'Abrir ACE IA'" :aria-expanded="floatingOpen" @click="floatingOpen ? floatingOpen=false : openFloating()">
        <X v-if="floatingOpen" :size="23"/><Sparkles v-else :size="23"/><span>{{ floatingOpen ? 'Recolher' : 'ACE IA DEV' }}</span>
      </button>
    </div>
    <div v-if="selected" class="dev-modal-scrim" role="presentation" @click.self="selected=null;details=null">
      <section class="dev-modal" role="dialog" aria-modal="true" :aria-label="`Gerenciar ${selected.name}`"><div class="dev-modal-header"><div><small>EMPRESA</small><h2>{{selected.name}}</h2></div><button aria-label="Fechar detalhes" @click="selected=null;details=null"><XCircle :size="22"/></button></div>
        <div class="dev-modal-body"><h3>Vagas da equipe</h3><p>Somente o DEV altera a quantidade máxima de usuários. O Master gerencia os integrantes.</p><div class="dev-inline"><input v-model.number="seats" type="number" min="1" max="5000" :aria-label="'Limite de usuários para '+selected.name"/><span>{{selected.members}} vaga(s) ocupada(s)</span><button :disabled="busy" @click="saveSeats">Salvar limite</button></div>
          <h3>Plano e situação de cobrança</h3><p v-if="selected.billing_source==='mercadopago'">Pagamento verificado pelo gateway. O DEV não pode marcar esta cobrança como paga manualmente.</p><p v-else>Status legado informado manualmente. Não representa consulta a uma fatura real.</p><div v-if="selected.billing_source!=='mercadopago'" class="dev-inline"><select v-model="plan" class="dev-select" aria-label="Plano"><option value="free">{{planLabels.free || 'Free'}}</option><option value="cloud">{{planLabels.cloud || 'Cloud'}}</option><option value="pro">{{planLabels.pro || 'Pro'}}</option></select><select v-model="billing" class="dev-select" aria-label="Situação da cobrança"><option v-for="(label,value) in billingLabels" :key="value" :value="value">{{label}}</option></select><button :disabled="busy" @click="saveBilling">Salvar cobrança</button></div>
          <h3>Conexões Google Drive <span class="dev-muted">({{details?.accounts?.length||0}})</span></h3><p>Somente contas, responsáveis e estado da autorização. Nenhum arquivo pode ser aberto aqui.</p><div v-if="!details?.accounts?.length" class="dev-no-connections"><Cloud :size="20"/> Nenhuma conta Google Drive conectada.</div>
          <div v-for="conn in details?.accounts||[]" :key="conn.id" class="dev-connection"><div><strong>{{conn.email||'Conta sem e-mail identificado'}}</strong><small>{{conn.connected_by?.name||'Responsável não identificado'}} · Conectada em {{fmtDate(conn.connected_at)}}</small><span class="dev-pill" :class="isProblem(conn.status)?'problem':'ok'">{{statusLabels[conn.status]}}</span><p v-if="conn.message" class="dev-health-reason">{{conn.message}}</p><small>Última verificação: {{fmtDate(conn.last_checked_at)}}</small></div><button class="dev-button-outline" :disabled="busy" @click="checkDrive(conn)"><RefreshCw :size="15"/> Verificar</button></div>
        </div>
      </section>
    </div>
  </div>
  <v-dialog v-model="profileDialog" max-width="1150" scrollable><div class="account-dev-dialog"><button class="account-dev-close" type="button" @click="profileDialog=false"><X :size="19"/> Fechar</button><ProfilePage @updated="emit('refresh-session')"/></div></v-dialog>
</template>
