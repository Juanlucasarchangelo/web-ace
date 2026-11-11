<script setup>
import { ref } from 'vue';
import { http, ensureCsrf } from '../lib/http';
import ThemeSwitcher from '../components/ThemeSwitcher.vue';
const p = new URLSearchParams(window.location.search);
const application_id = p.get('solicitacao') || '';
const token = p.get('codigo') || '';
const message = ref(''); const error = ref(''); const busy = ref(false); const checkoutUrl = ref('');
async function verify() {
  busy.value=true; error.value='';
  try { await ensureCsrf(); const response = (await http.post('/billing/verify-email', {application_id,token})).data; checkoutUrl.value = response.data?.checkout_url || ''; message.value=response.message || (checkoutUrl.value ? 'E-mail confirmado! Continue para o pagamento no Mercado Pago.' : 'E-mail confirmado.'); history.replaceState(null,'','/verificar-email'); }
  catch(e) { error.value=e.response?.data?.message || 'Link inválido ou expirado.'; }
  finally { busy.value=false; }
}
</script>
<template><div class="billing-page"><header class="billing-header"><a class="billing-brand" href="/">ace<span>.</span></a><ThemeSwitcher/></header><main class="billing-status-card billing-card"><span class="billing-eyebrow">VERIFICAÇÃO DE E-MAIL</span><h1>Confirme seu endereço de e-mail</h1><p>Valide o e-mail da conta Master. Para planos pagos, o acesso só será liberado após aprovação do pagamento pelo Mercado Pago.</p><p v-if="error" class="billing-alert error" role="alert">{{error}}</p><p v-if="message" class="billing-alert success" role="status">{{message}}</p><button v-if="!message" class="billing-primary" :disabled="busy || !token || !application_id" @click="verify">{{busy?'Confirmando…':'Confirmar e-mail'}}</button><a v-else-if="checkoutUrl" class="billing-primary" :href="checkoutUrl" rel="noopener noreferrer">Continuar para o Mercado Pago</a><a v-else class="billing-primary" href="/login">Entrar no ACE</a></main></div></template>
