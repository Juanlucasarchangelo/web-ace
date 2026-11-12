<script setup>
import { computed, onMounted, ref, nextTick } from 'vue';
import { Sparkles, X } from 'lucide-vue-next';
import { http, ensureCsrf } from '../lib/http';
import ThemeSwitcher from '../components/ThemeSwitcher.vue';
import AceChatPanel from '../components/AceChatPanel.vue';
import SupportTickets from '../components/SupportTickets.vue';
import { initializeBillingAi, clearAceAiSession } from '../lib/aceAiChat';
const props = defineProps({session:{type:Object,required:true}});
const emit = defineEmits(['logout','refresh']);
const plan = ref('cloud'); const cycle = ref('monthly'); const catalog=ref(null);
const paidPlans=computed(() => catalog.value?.plans?.filter(p=>p.id!=='free') || []); const billing = ref(null); const error = ref(''); const busy = ref(false);
const legalVersion = ref('2026-11-08');
const terms = ref(false); const privacy = ref(false);
const canRenew = computed(()=>billing.value?.can_renew);
const floatingOpen=ref(false); const floatingPanel=ref(null); const showSupport=ref(false);
function logoutBilling(){ clearAceAiSession(); emit('logout'); }
function openFloating(){ floatingOpen.value=true; nextTick(()=>floatingPanel.value?.focusInput()); }
async function load() {
  try { billing.value=(await http.get('/billing/current',{headers:{'X-Tenant-Id':props.session.tenant.id}})).data.data;
    catalog.value=(await http.get('/billing/plans')).data.data;
    legalVersion.value=catalog.value.legal_version;
    if(billing.value.plan!=='free' && paidPlans.value.some(p=>p.id===billing.value.plan)) plan.value=billing.value.plan; else plan.value=paidPlans.value[0]?.id||'cloud';
    if(billing.value.cycle) cycle.value=billing.value.cycle;
  } catch { error.value='Não foi possível consultar sua assinatura.'; }
}
async function renew() {
  if(busy.value || !terms.value || !privacy.value) {error.value='Leia os termos e a política de privacidade.';return;}
  busy.value=true; error.value='';
  try {await ensureCsrf(); const {data}=await http.post('/billing/renew', {plan:plan.value,cycle:cycle.value,
    accept_terms:true, acknowledge_privacy:true,terms_version:legalVersion.value}, {headers:{'X-Tenant-Id':props.session.tenant.id}});window.location.assign(data.data.checkout_url);}
  catch(e) {error.value=e.response?.data?.message||'Não foi possível criar o pagamento.';}
  finally {busy.value=false;}
}
onMounted(() => { initializeBillingAi(props.session.user.id, props.session.tenant.id); void load(); });
</script>
<template>
  <div class="billing-page"><header class="billing-header"><a href="/" class="billing-brand">ace<span>.</span></a><div class="billing-header-actions"><ThemeSwitcher/><button class="billing-secondary" @click="logoutBilling">Sair</button></div></header>
    <main v-if="!showSupport" class="billing-status-card billing-card"><span class="billing-eyebrow">SITUAÇÃO DA ASSINATURA</span><h1>Acesso temporariamente suspenso</h1><p>A assinatura da empresa <strong>{{props.session.tenant.name}}</strong> está vencida ou sem pagamento confirmado. Os dados permanecem preservados, mas o acesso ao workspace está bloqueado até a regularização.</p><template v-if="canRenew"><p>Como Master, você pode renovar um período de acesso. O ACE só reativa após a confirmação do Mercado Pago.</p><div class="billing-duo"><label>Plano <select v-model="plan"><option v-for="p in paidPlans" :key="p.id" :value="p.id">{{p.name}}</option></select></label><label>Período <select v-model="cycle"><option value="monthly">Mensal</option><option value="annual">Anual</option></select></label></div><label class="billing-check"><input v-model="terms" type="checkbox"/><span>Li e aceito os <a target="_blank" rel="noopener noreferrer" href="/termos">Termos de Contratação</a>.</span></label><label class="billing-check"><input v-model="privacy" type="checkbox"/><span>Estou ciente da <a target="_blank" rel="noopener noreferrer" href="/privacidade">Política de Privacidade</a>.</span></label><button class="billing-primary" :disabled="busy" @click="renew">{{busy?'Preparando…':'Renovar pelo Mercado Pago'}}</button></template><p v-else>Peça ao usuário Master da sua empresa para regularizar o pagamento.</p><p v-if="error" class="billing-alert error">{{error}}</p><button class="billing-secondary" @click="emit('refresh')">Já paguei — verificar acesso</button><button class="billing-secondary" type="button" @click="showSupport=true">Abrir suporte / chamados</button></main>
    <main v-else class="billing-support-wrap"><button class="billing-secondary" type="button" @click="showSupport=false">Voltar à assinatura</button><SupportTickets mode="member"/></main>
    <div class="ace-floating-area" :class="{'is-open':floatingOpen}">
      <div v-if="floatingOpen" class="ace-floating-panel-wrap" role="dialog" aria-label="Assistente de assinatura">
        <AceChatPanel ref="floatingPanel" mode="floating" page="assinatura_bloqueada" @close="floatingOpen=false" />
      </div>
      <button class="ace-floating-trigger" type="button" :aria-label="floatingOpen?'Recolher IA':'Abrir ajuda com assinatura'" :aria-expanded="floatingOpen" @click="floatingOpen?floatingOpen=false:openFloating()"><X v-if="floatingOpen" :size="23"/><Sparkles v-else :size="23"/><span>ACE IA</span></button>
    </div>
  </div>
</template>
