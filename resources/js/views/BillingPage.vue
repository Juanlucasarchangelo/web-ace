<script setup>
import { onMounted, ref, computed } from 'vue';
import { CreditCard, ShieldCheck, CalendarDays, CheckCircle2 } from 'lucide-vue-next';
import { http, ensureCsrf } from '../lib/http';
const info = ref(null);const catalog = ref(null);const plan=ref('cloud');const cycle=ref('monthly');const terms=ref(false);const privacy=ref(false);const error=ref('');const notice=ref('');const busy=ref(false);
const canRenew = computed(()=>info.value?.can_renew);
const brl = cents=>Number(cents/100).toLocaleString('pt-BR',{style:'currency',currency:'BRL'});
const selected = computed(()=>catalog.value?.plans?.find(p=>p.id===plan.value));
const paidPlans = computed(()=>catalog.value?.plans?.filter(p=>p.id!=='free') || []);
const statusLabel = computed(()=> {
  if(!info.value)return '—';
  if(info.value.blocked)return 'Vencido / bloqueado';
  if(info.value.provider==='free')return 'Gratuito';
  if(info.value.provider!=='mercadopago')return info.value.status==='paid'?'Informado como pago (manual)':'Situação manual: '+(info.value.status||'não verificada');
  return info.value.status==='paid'?'Pago e confirmado':'Pagamento '+(info.value.status||'pendente');
});
const amount = computed(()=>cycle.value==='monthly'?selected.value?.monthly_cents:selected.value?.annual_cents);
async function load() {
 try {const [a,b]=await Promise.all([http.get('/billing/current'),http.get('/billing/plans')]);info.value=a.data.data;catalog.value=b.data.data;if(info.value.plan!=='free' && b.data.data.plans?.some(p=>p.id===info.value.plan)) plan.value=info.value.plan; else plan.value=paidPlans.value[0]?.id||'cloud';if(info.value.cycle)cycle.value=info.value.cycle;}
 catch {error.value='Não foi possível consultar sua assinatura.';}
}
async function renew(){
 if(busy.value || !canRenew.value) return;
 if(!terms.value||!privacy.value){error.value='Leia e aceite os documentos antes de prosseguir.';return;}
 busy.value=true;error.value='';
 try {await ensureCsrf(); const r=await http.post('/billing/renew',{plan:plan.value,cycle:cycle.value,accept_terms:true,acknowledge_privacy:true,terms_version:catalog.value.legal_version});window.location.assign(r.data.data.checkout_url);}
 catch(e){error.value=e.response?.data?.message||'Não foi possível iniciar o pagamento.';}
 finally{busy.value=false;}
}
onMounted(load);
</script>
<template>
  <section class="ace-page billing-internal">
    <div class="ace-page-head">
      <div>
        <div class="ace-eyebrow"><CreditCard :size="16" /> SUA ASSINATURA</div>
        <h1>Plano e pagamento<span>.</span></h1>
        <p>Consulte seu plano e o período contratado. Pagamentos são processados pelo Mercado Pago.</p>
      </div>
      <button class="ace-btn ace-btn-outline" type="button" @click="load">Atualizar</button>
    </div>

    <div class="ace-panel billing-internal-panel">
      <div class="billing-internal-metrics" aria-label="Resumo da assinatura">
        <div><span>Plano atual</span><strong>{{ info?.plan_name || info?.plan?.toUpperCase() || '—' }}</strong></div>
        <div><span>Origem</span><strong>{{ info?.provider === 'mercadopago' ? 'Mercado Pago' : info?.provider === 'free' ? 'Gratuito' : 'Conta legada' }}</strong></div>
        <div><span>Situação</span><strong>{{ statusLabel }}</strong></div>
        <div><span>Válido até</span><strong>{{ info?.expires_at ? new Date(info.expires_at).toLocaleDateString('pt-BR') : 'Não aplicável' }}</strong></div>
      </div>

      <div v-if="canRenew" class="billing-internal-renew">
        <div class="billing-renew-head">
          <div>
            <h2>Contratar ou renovar</h2>
            <p>Escolha o plano e o período. Se houver um período pago, ele será preservado na renovação.</p>
          </div>
          <span class="billing-renew-tag"><ShieldCheck :size="15" /> Sem cobrança automática</span>
        </div>

        <div class="billing-renew-fields">
          <fieldset class="billing-choice-field">
            <legend>Escolha o plano</legend>
            <div class="billing-plan-options" role="radiogroup" aria-label="Plano">
              <button v-for="p in paidPlans" :key="p.id" type="button"
                class="billing-choice-card" :class="{ 'is-selected': plan === p.id }"
                role="radio" :aria-checked="plan === p.id" @click="plan = p.id">
                <span class="billing-choice-text"><strong>{{ p.name }}</strong><small>{{ brl(cycle === 'monthly' ? p.monthly_cents : p.annual_cents) }}{{ cycle === 'monthly' ? ' / mês' : ' / ano' }}</small></span>
                <span class="billing-choice-indicator" aria-hidden="true"><CheckCircle2 v-if="plan === p.id" :size="18" /></span>
              </button>
            </div>
          </fieldset>

          <fieldset class="billing-choice-field">
            <legend>Período de contratação</legend>
            <div class="billing-cycle-options" role="radiogroup" aria-label="Período">
              <button type="button" role="radio" :aria-checked="cycle === 'monthly'" :class="{ 'is-selected': cycle === 'monthly' }" @click="cycle = 'monthly'">Mensal</button>
              <button type="button" role="radio" :aria-checked="cycle === 'annual'" :class="{ 'is-selected': cycle === 'annual' }" @click="cycle = 'annual'">Anual</button>
            </div>
          </fieldset>
        </div>

        <p v-if="selected?.description" class="billing-renew-description">{{ selected.description }}</p>

        <div class="billing-renew-total">
          <span>Total do pedido <small>Valor calculado pelo servidor</small></span>
          <strong>{{ brl(amount || 0) }}</strong>
        </div>

        <div class="billing-renew-consent">
          <label class="billing-check"><input v-model="terms" type="checkbox" /><span>Li e aceito os <a target="_blank" rel="noopener noreferrer" href="/termos">Termos de Uso</a>.</span></label>
          <label class="billing-check"><input v-model="privacy" type="checkbox" /><span>Estou ciente da <a target="_blank" rel="noopener noreferrer" href="/privacidade">Política de Privacidade</a>.</span></label>
        </div>

        <button class="ace-btn ace-btn-primary billing-renew-submit" type="button"
          :disabled="busy || !catalog?.checkout_ready" @click="renew"><CreditCard :size="17" /> {{ busy ? 'Aguarde…' : 'Pagar no Mercado Pago' }}</button>
        <p v-if="catalog && !catalog.checkout_ready" class="billing-setup-alert" role="status">Checkout em configuração. Os pagamentos estão indisponíveis no momento.</p>
      </div>
      <div v-else class="billing-renew-readonly"><ShieldCheck :size="19" /><span>Somente o Master pode contratar ou renovar planos.</span></div>
      <p v-if="error" class="ace-error" role="alert">{{ error }}</p>
    </div>
  </section>
</template>
