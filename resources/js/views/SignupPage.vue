<script setup>
import { computed, onMounted, ref } from 'vue';
import { ArrowLeft, ArrowRight, Check, CreditCard, LockKeyhole, ShieldCheck } from 'lucide-vue-next';
import { http, ensureCsrf } from '../lib/http';
import { serverError } from '../lib/ace';
import ThemeSwitcher from '../components/ThemeSwitcher.vue';

const params = new URLSearchParams(window.location.search);
const plan = ref(['free','cloud','pro'].includes(params.get('plano')) ? params.get('plano') : 'cloud');
const cycle = ref(['monthly','annual'].includes(params.get('ciclo')) ? params.get('ciclo') : 'monthly');
const catalog = ref(null);
const couponCode = ref(params.get('cupom') || '');
const couponQuote = ref(null);
const couponError = ref('');
const checkingCoupon = ref(false);
const form = ref({ name:'', company:'', email:'', password:'', password_confirmation:'', accept_terms:false, acknowledge_privacy:false });
const sending = ref(false);
const result = ref('');
const errorMessage = ref('');
const legalVersion = computed(() => catalog.value?.legal_version || '2026-11-08');
const planInfo = computed(() => catalog.value?.plans?.find(p => p.id === plan.value));
const amount = computed(() => cycle.value === 'annual' ? planInfo.value?.annual_cents : planInfo.value?.monthly_cents);
const finalAmount = computed(() => couponQuote.value?.amount_cents ?? amount.value);
function resetCoupon(){ couponQuote.value=null; couponError.value=''; }
async function validateCoupon(){
  resetCoupon();
  if(!couponCode.value.trim())return;
  if(plan.value==='free'){couponError.value='Cupons são exclusivos para planos pagos.';return;}
  checkingCoupon.value=true;
  try {const {data}=await http.post('/billing/coupon/quote',{coupon:couponCode.value.trim().toUpperCase(),plan:plan.value,cycle:cycle.value});couponQuote.value=data.data;}
  catch(e){couponError.value=e.response?.data?.errors?.coupon?.[0]||serverError(e);}
  finally{checkingCoupon.value=false;}
}

const brl = cents => (Number(cents || 0)/100).toLocaleString('pt-BR', {style:'currency',currency:'BRL'});
const isPaid = computed(() => plan.value !== 'free');
async function load() {
  try { catalog.value = (await http.get('/billing/plans')).data.data; if (!catalog.value.plans?.some(p => p.id === plan.value)) plan.value = catalog.value.plans?.[0]?.id || 'free'; }
  catch(e) { errorMessage.value = serverError(e); }
}
async function register() {
  if (sending.value || result.value) return;
  if (couponCode.value.trim() && (!couponQuote.value || couponQuote.value.coupon !== couponCode.value.trim().toUpperCase())) { errorMessage.value='Valide o cupom antes de continuar.'; return; }
  errorMessage.value = '';
  if (!form.value.accept_terms || !form.value.acknowledge_privacy) { errorMessage.value = 'Leia e aceite os Termos e confirme a ciência da Política de Privacidade.'; return; }
  if (form.value.password.length < 12 || form.value.password !== form.value.password_confirmation) { errorMessage.value = 'Use pelo menos 12 caracteres e confirme a mesma senha.'; return; }
  sending.value = true;
  try {
    await ensureCsrf();
    const {data} = await http.post('/billing/signup', { ...form.value, plan:plan.value, cycle:cycle.value,
      terms_version:legalVersion.value, privacy_version:legalVersion.value, coupon: couponQuote.value?.coupon || null }, {timeout:30000});
    form.value.password = ''; form.value.password_confirmation = '';
    if (data.data?.checkout_url) { window.location.assign(data.data.checkout_url); return; }
    result.value = data.data?.message || data.message || 'Cadastro recebido.';
  } catch(e) { errorMessage.value = e.response?.data?.errors ? Object.values(e.response.data.errors).flat().join(' ') : serverError(e); }
  finally { sending.value = false; }
}
onMounted(load);
</script>
<template>
  <div class="billing-page">
    <header class="billing-header"><a href="/" class="billing-brand">ace<span>.</span></a><div class="billing-header-actions"><ThemeSwitcher/><a href="/login">Já tenho conta</a></div></header>
    <main class="billing-shell">
      <div class="billing-heading"><a href="/#planos" class="billing-back"><ArrowLeft :size="17"/> Voltar aos planos</a><span class="billing-eyebrow"><ShieldCheck :size="16"/> ASSINATURA ACE</span><h1>Seu espaço de trabalho começa aqui<span>.</span></h1><p>Escolha o período, revise o valor e cadastre o Master da sua empresa. Primeiro você confirma seu e-mail; em seguida, o pagamento ocorre no ambiente seguro do Mercado Pago.</p></div>
      <div class="billing-columns">
        <section class="billing-card billing-form" aria-label="Cadastro da empresa">
          <div class="billing-section-title"><strong>1. Selecione o plano</strong><small>Valores em reais, impostos e condições informados no checkout</small></div>
          <div class="billing-plan-select"><button v-for="p in catalog?.plans || []" :key="p.id" type="button" :class="{selected:plan===p.id}" :aria-pressed="plan===p.id" @click="plan=p.id;resetCoupon()"><strong>{{p.name}}</strong><span>{{brl(cycle==='annual'?p.annual_cents:p.monthly_cents)}}</span></button></div>
          <div class="billing-cycle" role="group" aria-label="Periodicidade"><button type="button" :aria-pressed="cycle==='monthly'" :class="{selected:cycle==='monthly'}" @click="cycle='monthly';resetCoupon()">Mensal</button><button type="button" :aria-pressed="cycle==='annual'" :class="{selected:cycle==='annual'}" @click="cycle='annual';resetCoupon()">Anual <span v-if="plan!=='free'">economize</span></button></div>
          <form class="billing-signup-form" @submit.prevent="register">
            <p v-if="planInfo?.description" class="billing-trust">{{planInfo.description}}</p><div class="billing-section-title"><strong>2. Dados da conta Master</strong><small>O Master gerencia a equipe; o DEV não acessa dados internos da empresa</small></div>
            <label>Nome completo <input v-model.trim="form.name" required minlength="2" maxlength="140" autocomplete="name"/></label>
            <label>Empresa / nome do workspace <input v-model.trim="form.company" required minlength="2" maxlength="140" autocomplete="organization"/></label>
            <label>E-mail de acesso <input v-model.trim="form.email" required type="email" autocomplete="email" maxlength="255"/></label>
            <div class="billing-duo"><label>Senha forte <input v-model="form.password" required type="password" minlength="12" maxlength="128" autocomplete="new-password"/></label><label>Confirmar senha <input v-model="form.password_confirmation" required type="password" minlength="12" maxlength="128" autocomplete="new-password"/></label></div>
            <div v-if="isPaid" class="billing-section-title"><strong>3. Cupom de afiliado (opcional)</strong><small>Desconto informado antes de confirmar o pré-cadastro</small></div>
            <div v-if="isPaid" class="billing-duo"><label>Código do cupom<input v-model.trim="couponCode" maxlength="32" placeholder="EX: MARIA10" @input="resetCoupon"/></label><button type="button" class="billing-primary" style="align-self:end" :disabled="checkingCoupon || !couponCode.trim()" @click="validateCoupon">{{checkingCoupon?'Validando…':'Aplicar cupom'}}</button></div>
            <p v-if="couponQuote" class="billing-trust">Cupom {{couponQuote.coupon}} aplicado: desconto de {{couponQuote.discount_percent}}% ({{brl(couponQuote.discount_cents)}}).</p>
            <p v-if="couponError" class="billing-alert error" role="alert">{{couponError}}</p>
            <div class="billing-section-title"><strong>{{isPaid?'4':'3'}}. Leia e confirme</strong></div>
            <label class="billing-check"><input v-model="form.accept_terms" type="checkbox" required/><span>Li e aceito os <a href="/termos" target="_blank" rel="noopener noreferrer">Termos de Uso e Contratação</a>, incluindo preço, periodicidade, cancelamento e direito de arrependimento aplicável.</span></label>
            <label class="billing-check"><input v-model="form.acknowledge_privacy" type="checkbox" required/><span>Declaro que tive acesso à <a href="/privacidade" target="_blank" rel="noopener noreferrer">Política de Privacidade</a> e estou ciente do tratamento de dados necessário à prestação do serviço.</span></label>
            <div v-if="errorMessage" class="billing-alert error" role="alert">{{errorMessage}}</div>
            <div v-if="result" class="billing-alert success" role="status">{{result}} <a href="/login">Ir para login</a></div>
            <button v-else type="submit" class="billing-primary" :disabled="sending || !catalog || checkingCoupon">
              <LockKeyhole :size="18"/> {{sending?'Preparando cadastro…': isPaid?(catalog.checkout_ready?'Continuar cadastro':'Registrar pré-cadastro sem cobrança'):'Cadastrar no plano Free'}} <ArrowRight :size="18"/>
            </button>
            <p v-if="isPaid && catalog && !catalog.checkout_ready" class="billing-setup-alert">Pré-cadastro disponível. Nenhum pagamento será iniciado até que a integração seja habilitada e o e-mail confirmado.</p>
          </form>
        </section>
        <aside class="billing-card billing-summary"><span class="billing-eyebrow">RESUMO DO PEDIDO</span><h2>ACE {{planInfo?.name || 'Cloud'}}</h2><div v-if="couponQuote" class="billing-trust"><s>{{brl(amount)}}</s> · {{couponQuote.discount_percent}}% de desconto</div><div class="billing-amount">{{brl(finalAmount)}} <small>/ {{cycle==='annual'?'12 meses':'mês'}}</small></div><p v-if="cycle==='annual'&&isPaid">Equivalente a {{brl(Number(amount||0)/12)}} por mês. Cobrança integral anual.</p><p v-else-if="isPaid">Cobrança antecipada por 1 mês.</p><p v-else>Sem cobrança. Liberação após confirmar o e-mail.</p><hr/><div class="billing-trust"><Check :size="18"/> Preço calculado no servidor</div><div class="billing-trust"><Check :size="18"/> Pix, cartão ou boleto, conforme disponibilidade no Mercado Pago</div><div class="billing-trust"><Check :size="18"/> Acesso pago somente após confirmação do pagamento</div><div class="billing-trust"><Check :size="18"/> Sem cobrança automática recorrente nesta etapa</div><p class="billing-footnote">O processamento do pagamento ocorre fora do ACE. Não coletamos nem armazenamos dados completos do seu cartão.</p><p class="billing-footnote">Precisa de ajuda? Consulte os contatos publicados nos <a href="/termos">Termos</a>.</p></aside>
      </div>
    </main>
  </div>
</template>
