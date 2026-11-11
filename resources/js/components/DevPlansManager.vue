<script setup>
import { computed, onMounted, ref } from 'vue';
import { CreditCard, CheckCircle2, Save, ShieldCheck } from 'lucide-vue-next';
import { http, ensureCsrf } from '../lib/http';
import { serverError } from '../lib/ace';

const emit = defineEmits(['updated']);
const plans = ref([]);
const loading = ref(false);
const busy = ref(false);
const error = ref('');
const notice = ref('');
const history = ref([]);
const current = ref(null);
const form = ref(null);
const currency = cents => (Number(cents || 0) / 100).toLocaleString('pt-BR', {style:'currency', currency:'BRL'});
const parseBRL = raw => {
  const s = String(raw ?? '').trim().replace(/\s/g, '').replace(/^R\$\s*/i, '');
  if (!/^\d{1,7}(?:[.,]\d{1,2})?$/.test(s)) return null;
  const [whole, decimal = ''] = s.replace(',', '.').split('.');
  return Number(whole) * 100 + Number(decimal.padEnd(2, '0'));
};
const selected = computed(() => plans.value.find(p => p.id === current.value));
async function load() {
  loading.value = true; error.value = '';
  try {
    const {data} = await http.get('/dev/plans');
    plans.value = data.data.plans;
    if (!current.value) current.value = plans.value[0]?.id || null;
    if (current.value) choose(current.value);
  } catch(e) { error.value = serverError(e); }
  finally { loading.value = false; }
}
function choose(id) {
  current.value = id;
  const p = plans.value.find(item => item.id === id);
  if (!p) return;
  form.value = {
    title: p.name, description: p.description || '',
    features: (p.features || []).join('\n'),
    monthly: (p.monthly_cents / 100).toFixed(2).replace('.', ','),
    annual: (p.annual_cents / 100).toFixed(2).replace('.', ','),
    is_active: p.is_active,
  };
  error.value = ''; notice.value = '';
  void loadHistory(id);
}
async function loadHistory(id) {
  history.value = [];
  try { history.value = (await http.get(`/dev/plans/${encodeURIComponent(id)}/history`)).data.data || []; } catch { /* A edição permanece disponível sem a lista de histórico. */ }
}
async function save() {
  if (!form.value || busy.value) return;
  const monthly = parseBRL(form.value.monthly);
  const annual = parseBRL(form.value.annual);
  const features = form.value.features.split('\n').map(x => x.trim()).filter(Boolean);
  if (monthly === null || annual === null) { error.value = 'Informe valores válidos em reais (exemplo: 129,00).'; return; }
  if (features.length < 1 || features.length > 12) { error.value = 'Informe de 1 a 12 benefícios, um por linha.'; return; }
  if (!window.confirm(`Confirmar mudanças no plano ${selected.value?.name}? Os novos preços valerão para pedidos futuros; cobranças emitidas preservam seu valor.`)) return;
  busy.value = true; error.value = ''; notice.value = '';
  try {
    await ensureCsrf();
    const {data} = await http.patch(`/dev/plans/${encodeURIComponent(current.value)}`, {
      title: form.value.title, description: form.value.description,
      features, monthly_cents: monthly, annual_cents: annual,
      is_active: form.value.is_active, confirm: 'ALTERAR PLANO',
    });
    plans.value = data.data.plans;
    notice.value = data.message || 'Plano atualizado.';
    choose(current.value);
    // choose clears notices, so report final success after replacing current state
    notice.value = 'Plano salvo. Os novos valores já são utilizados nas novas contratações.';
    emit('updated');
  } catch(e) { error.value = e.response?.data?.errors ? Object.values(e.response.data.errors).flat().join(' ') : serverError(e); }
  finally { busy.value = false; }
}
onMounted(load);
</script>
<template>
  <section class="dev-plan-manager" aria-label="Gerenciar catálogo de planos">
    <header class="dev-plan-manager-header"><div><div class="dev-overline"><CreditCard :size="16"/> CATÁLOGO DE ASSINATURAS</div><h2>Planos e preços<span>.</span></h2><p>Edite a apresentação e os valores sem modificar código. O identificador técnico do plano e os recursos de cada categoria permanecem protegidos.</p></div><button class="dev-button-outline" :disabled="loading || busy" @click="load">Atualizar</button></header>
    <p v-if="error" role="alert" class="dev-banner dev-error">{{error}}</p>
    <p v-if="notice" role="status" class="dev-banner dev-success">{{notice}}</p>
    <div class="dev-plan-list" role="group" aria-label="Escolher plano para editar"><button v-for="p in plans" :key="p.id" type="button" :aria-pressed="current===p.id" :class="{selected: current===p.id}" @click="choose(p.id)"><strong>{{p.name}}</strong><small>{{p.id}} · {{p.is_active ? 'Disponível' : 'Pausado'}}</small><span>{{currency(p.monthly_cents)}} / mensal</span></button></div>
    <form v-if="form && selected" class="dev-plan-form" @submit.prevent="save">
      <div class="dev-plan-form-header"><strong>Editar plano {{selected.id.toUpperCase()}}</strong><span><ShieldCheck :size="15"/> Uso exclusivo do DEV</span></div>
      <div class="dev-plan-fields"><label>Título público<input v-model.trim="form.title" required maxlength="80" minlength="2"/></label><label>Descrição<textarea v-model.trim="form.description" required maxlength="800" rows="3"/></label></div>
      <label>Benefícios, um por linha<textarea v-model="form.features" rows="5" maxlength="1940" required placeholder="Um benefício por linha"/></label>
      <div class="dev-plan-prices"><label>Preço mensal (R$)<input v-model.trim="form.monthly" inputmode="decimal" required :readonly="current==='free'"/></label><label>Preço anual total (R$)<input v-model.trim="form.annual" inputmode="decimal" required :readonly="current==='free'"/></label></div>
      <label class="dev-plan-toggle"><input v-model="form.is_active" type="checkbox" :disabled="current==='free'"/> Disponível para novas contratações</label>
      <p class="dev-plan-hint">O valor anual é o total cobrado de uma vez por 12 meses. Ex.: Cloud 109/mês no anual = R$ 1.308,00. Pedidos já emitidos não têm seu valor modificado.</p>
      <button type="submit" class="dev-plan-save" :disabled="loading || busy"><Save :size="17"/> {{busy?'Salvando…':'Salvar alterações do plano'}} <CheckCircle2 :size="17"/></button>
      <details class="dev-plan-history"><summary>Histórico de alterações ({{history.length}} recentes)</summary>
        <p v-if="!history.length">Nenhuma alteração de plano registrada.</p>
        <ul v-else><li v-for="item in history" :key="item.id"><strong>{{new Date(item.created_at).toLocaleString('pt-BR')}}</strong> — Operador #{{item.actor_id}}: {{item.before.title}} ({{currency(item.before.monthly_cents)}}/mês) → {{item.after.title}} ({{currency(item.after.monthly_cents)}}/mês).</li></ul>
      </details>
    </form>
  </section>
</template>
<style scoped>
.dev-plan-manager{background:var(--ace-surface,#fff);border:1px solid var(--ace-border,#dde5e9);border-radius:18px;padding:26px;color:var(--ace-text,#192d3b)}
.dev-plan-manager-header{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;margin-bottom:20px}
.dev-plan-manager-header h2{font-size:27px;font-weight:850;margin:6px 0;color:var(--ace-text)}.dev-plan-manager-header h2 span{color:#edae20}.dev-plan-manager-header p{max-width:690px;color:var(--ace-muted);font-size:13px}
.dev-plan-list{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:22px 0}
.dev-plan-list button{display:flex;flex-direction:column;align-items:flex-start;gap:7px;border:1px solid var(--ace-border);background:var(--ace-bg);border-radius:12px;padding:16px;color:var(--ace-text);text-align:left;cursor:pointer}
.dev-plan-list button.selected{border-color:#35a58a;box-shadow:0 0 0 2px #35a58a30}.dev-plan-list strong{font-size:17px}.dev-plan-list small{color:var(--ace-muted)}.dev-plan-list span{font-size:13px}
.dev-plan-form{display:grid;gap:16px}.dev-plan-form-header{display:flex;justify-content:space-between;gap:15px}.dev-plan-form-header span{display:flex;align-items:center;gap:5px;font-size:12px;color:var(--ace-muted)}
.dev-plan-fields,.dev-plan-prices{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.dev-plan-form label{display:grid;gap:8px;color:var(--ace-text);font-size:13px;font-weight:650}.dev-plan-form input:not([type=checkbox]),.dev-plan-form textarea{width:100%;min-width:0;box-sizing:border-box;border:1px solid var(--ace-border);border-radius:10px;background:var(--ace-bg);color:var(--ace-text);padding:12px;font:inherit;font-weight:400}
.dev-plan-form input:focus,.dev-plan-form textarea:focus{outline:2px solid #35a58a60;outline-offset:2px}.dev-plan-toggle{display:flex!important;align-items:center;gap:10px!important}.dev-plan-toggle input{accent-color:#12856f;width:17px;height:17px}.dev-plan-hint{font-size:12px;color:var(--ace-muted);margin:0}.dev-plan-save{justify-self:start;display:flex;gap:8px;align-items:center;border:0;border-radius:10px;background:#ffc734;color:#16212a;padding:12px 17px;font-weight:750;cursor:pointer}.dev-plan-save:disabled{opacity:.55}
.dev-plan-history{font-size:12px;color:var(--ace-muted);border-top:1px solid var(--ace-border);padding-top:12px}.dev-plan-history summary{cursor:pointer;font-weight:700;color:var(--ace-text)}.dev-plan-history ul{display:grid;gap:8px;padding-left:18px}
@media(max-width:760px){.dev-plan-list{grid-template-columns:1fr}.dev-plan-fields,.dev-plan-prices{grid-template-columns:1fr}.dev-plan-manager{padding:17px}}
</style>
