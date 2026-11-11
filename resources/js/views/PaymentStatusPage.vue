<script setup>
import { onMounted, onBeforeUnmount, ref, computed } from 'vue';
import { http } from '../lib/http';
import ThemeSwitcher from '../components/ThemeSwitcher.vue';
import { RefreshCw, ShieldCheck, Clock3 } from 'lucide-vue-next';
const order = new URLSearchParams(window.location.search).get('pedido');
const status = ref('loading');
const error = ref('');
const end = ref(null);
let interval;
const text = computed(() => ({
  approved: 'Pagamento confirmado pelo Mercado Pago. Seu acesso já pode ser utilizado.',
  pending: 'Aguardando confirmação do Mercado Pago. Pix e boleto podem permanecer pendentes até a compensação.',
  initiated: 'Checkout iniciado. Conclua seu pagamento no Mercado Pago.',
  rejected: 'Pagamento recusado. Sua conta não foi ativada.',
  refunded: 'Pagamento reembolsado. Acesso sujeito a bloqueio.',
  charged_back: 'Pagamento contestado. Entre em contato com o suporte.',
  gateway_error: 'Houve uma falha no checkout. Nenhum acesso foi liberado.',
  loading: 'Consultando o status confirmado no servidor…',
})[status.value] || 'Pagamento em análise. Consulte novamente mais tarde.');
async function refresh() {
  if (!order || !/^[0-9a-f-]{36}$/i.test(order)) { error.value='Pedido inválido.'; return; }
  try { const {data} = await http.get(`/billing/orders/${order}`); status.value=data.data.status; end.value=data.data.period_end; error.value=''; }
  catch(e) { error.value = e.response?.status === 404 ? 'Não foi possível validar o acompanhamento neste navegador. Volte ao mesmo navegador usado na contratação ou entre em contato com o suporte.' : 'Não foi possível consultar o pedido no momento.'; }
}
onMounted(() => { refresh(); interval=setInterval(() => { if(status.value!=='approved') refresh(); }, 12000); });
onBeforeUnmount(() => clearInterval(interval));
</script>
<template>
  <div class="billing-page"><header class="billing-header"><a href="/" class="billing-brand">ace<span>.</span></a><ThemeSwitcher/></header><main class="billing-status-card billing-card"><div class="billing-status-icon"><ShieldCheck v-if="status==='approved'" :size="36"/><Clock3 v-else :size="36"/></div><span class="billing-eyebrow">PAGAMENTO · STATUS VERIFICADO</span><h1>{{status==='approved'?'Pagamento aprovado!':'Acompanhamento do pedido'}}</h1><p>{{text}}</p><p v-if="end">Período pago até: {{new Date(end).toLocaleDateString('pt-BR')}}</p><p v-if="error" class="billing-alert error" role="alert">{{error}}</p><div class="billing-status-actions"><a v-if="status==='approved'" class="billing-primary" href="/login">Entrar no ACE</a><button type="button" class="billing-secondary" @click="refresh"><RefreshCw :size="16"/> Atualizar status</button></div><small>O retorno do navegador não comprova pagamento. O ACE só libera contas após confirmação autenticada pelo backend.</small></main></div>
</template>
