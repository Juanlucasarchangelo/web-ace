<script setup>
import { onMounted, ref, computed } from 'vue';
import { UsersRound, Globe2, KeyRound, ShieldCheck, ArrowUpRight, Plus, Activity, CheckCircle2, PauseCircle, ChartNoAxesColumnIncreasing, ArrowRight } from 'lucide-vue-next';
import { http } from '../lib/http';
import { serverError, siteStatus, fmtDate } from '../lib/ace';
const props = defineProps({ tenantName: { type: String, default: '' } });
const emit = defineEmits(['navigate']);
const state = ref(null), busy = ref(true), error = ref('');
const fmt = (n) => new Intl.NumberFormat('pt-BR').format(n || 0);
const siteRate = computed(() => state.value?.sites_total ? Math.round(state.value.sites_active / state.value.sites_total * 100) : 0);
const clientRate = computed(() => state.value?.clients_total ? Math.round(state.value.clients_active / state.value.clients_total * 100) : 0);
async function load() {
    busy.value = true;
    try { state.value = (await http.get('/dashboard')).data.data; }
    catch (e) { error.value = serverError(e); }
    finally { busy.value = false; }
}
onMounted(load);
const actionName = (action) => ({ 'auth.login':'Entrou na plataforma', 'client.created':'Cliente cadastrado', 'client.updated':'Cliente atualizado', 'site.created':'Site cadastrado', 'site.updated':'Site atualizado', 'access.created':'Acesso adicionado', 'access.secret.revealed':'Credencial consultada', 'site.registry.revealed':'Registro consultado' }[action] || action.replaceAll('.', ' · '));
</script>
<template>
    <div class="ace-page">
        <div class="ace-page-head"><div><div class="ace-eyebrow"><span class="ace-eyebrow-dot"></span> VISÃO ESTRATÉGICA</div><h1>Painel de controle<span>.</span></h1><p>Central de operações de {{ tenantName }}. Tudo o que importa, em um só lugar.</p></div><button class="ace-btn ace-btn-outline" @click="load"><Activity :size="16"/> Atualizar dados</button></div>
        <div v-if="error" class="ace-error">{{ error }}</div>
        <div v-if="busy" class="ace-skeleton">Carregando indicadores...</div>
        <template v-else-if="state">
            <div class="ace-metrics">
                <div class="ace-metric" @click="emit('navigate','clientes')"><div class="ace-metric-icon teal"><UsersRound :size="20"/></div><span>Clientes cadastrados</span><strong>{{ fmt(state.clients_total) }}</strong><small><CheckCircle2 :size="14" /> {{ fmt(state.clients_active) }} ativos <span>· {{ fmt(state.clients_inactive) }} inativos</span></small><ArrowUpRight class="ace-metric-arrow" :size="18"/></div>
                <div class="ace-metric" @click="emit('navigate','sites')"><div class="ace-metric-icon blue"><Globe2 :size="20"/></div><span>Sites gerenciados</span><strong>{{ fmt(state.sites_total) }}</strong><small><CheckCircle2 :size="14" /> {{ fmt(state.sites_active) }} ativos <span>· {{ fmt(state.sites_inactive) }} inativos</span></small><ArrowUpRight class="ace-metric-arrow" :size="18"/></div>
                <div class="ace-metric" @click="emit('navigate','cofre')"><div class="ace-metric-icon purple"><KeyRound :size="20"/></div><span>Acessos protegidos</span><strong>{{ fmt(state.accesses_total) }}</strong><small><ShieldCheck :size="14"/> Segredos criptografados</small><ArrowUpRight class="ace-metric-arrow" :size="18"/></div>
                <div class="ace-metric" @click="emit('navigate','equipe')"><div class="ace-metric-icon amber"><UsersRound :size="20"/></div><span>Membros da equipe</span><strong>{{ fmt(state.team_total) }}</strong><small><ShieldCheck :size="14"/> Perfis de acesso</small><ArrowUpRight class="ace-metric-arrow" :size="18"/></div>
            </div>
            <div class="ace-dashboard-lower">
                <section class="ace-panel ace-health"><div class="ace-panel-heading"><div><div class="ace-small-label">STATUS DA OPERAÇÃO</div><h2>Visão de atividade</h2></div><ChartNoAxesColumnIncreasing :size="20"/></div>
                    <div class="ace-health-row"><div><strong>Clientes ativos</strong><small>{{ clientRate }}% da carteira</small></div><strong>{{ fmt(state.clients_active) }} / {{ fmt(state.clients_total) }}</strong></div><div class="ace-progress-track"><span :style="{ width: clientRate + '%' }"></span></div>
                    <div class="ace-health-row"><div><strong>Sites ativos</strong><small>{{ siteRate }}% da operação</small></div><strong>{{ fmt(state.sites_active) }} / {{ fmt(state.sites_total) }}</strong></div><div class="ace-progress-track blue"><span :style="{ width: siteRate + '%' }"></span></div>
                    <div class="ace-status-summary"><span><CheckCircle2 :size="16"/> {{ state.sites_active }} sites ativos</span><span><PauseCircle :size="16"/> {{ state.sites_maintenance }} em manutenção</span></div>
                    <div class="ace-quick-actions"><button class="ace-btn ace-btn-primary" @click="emit('navigate','clientes')"><Plus :size="17"/> Novo cliente</button><button class="ace-btn ace-btn-soft" @click="emit('navigate','sites')"><Plus :size="17"/> Novo site</button></div>
                </section>
                <section class="ace-panel"><div class="ace-panel-heading"><div><div class="ace-small-label">CARTEIRA DIGITAL</div><h2>Sites recentes</h2></div><button class="ace-link" @click="emit('navigate','sites')">Ver todos <ArrowRight :size="15"/></button></div>
                    <div v-if="!state.latest_sites.length" class="ace-empty-small">Nenhum site cadastrado ainda.</div>
                    <div v-for="site in state.latest_sites" :key="site.id" class="ace-site-mini"><div class="ace-site-favicon"><Globe2 :size="18"/></div><div><strong>{{ site.name }}</strong><small>{{ site.client?.name || 'Sem cliente' }} · {{ site.domain }}</small></div><span class="ace-status" :class="site.status">{{ siteStatus[site.status] }}</span></div>
                </section>
            </div>
            <section class="ace-panel ace-activity-preview"><div class="ace-panel-heading"><div><div class="ace-small-label">RASTREABILIDADE</div><h2>Atividades recentes</h2></div><button class="ace-link" @click="emit('navigate','atividade')">Auditoria completa <ArrowRight :size="15"/></button></div><div v-if="!state.recent_activity.length" class="ace-empty-small">Nenhuma atividade recente.</div><div class="ace-activity-strip"><div v-for="a in state.recent_activity" :key="a.id" class="ace-activity-mini"><span class="ace-activity-dot"></span><div><strong>{{ actionName(a.action) }}</strong><small>{{ a.user?.name || 'Sistema' }} · {{ fmtDate(a.created_at) }}</small></div></div></div></section>
        </template>
    </div>
</template>
