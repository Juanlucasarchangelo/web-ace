<script setup>
import { computed, onMounted, ref } from 'vue';
import { http, ensureCsrf } from '../lib/http';
import { serverError } from '../lib/ace';
const data = ref({affiliates:[],coupons:[],summary:{}});
const candidates = ref([]);
const search = ref('');
const userId = ref(null);
const form = ref({affiliate_id:'',code:'',discount_percent:10,plan:'',max_uses:'',expires_at:''});
const sales = ref([]);
const page = ref(1);
const lastPage = ref(1);
const couponFilter = ref('');
const busy = ref(false);
const loading = ref(false);
const error = ref('');
const notice = ref('');
const money = n => (Number(n||0)/100).toLocaleString('pt-BR',{style:'currency',currency:'BRL'});
const when = s => s ? new Date(s).toLocaleString('pt-BR') : 'Sem vencimento';
const hasAffiliates = computed(() => data.value.affiliates.some(a => a.is_active));
const statusName = s => ({approved:'Pago',pending:'Pendente',initiated:'Iniciado',gateway_error:'Falha gateway',rejected:'Rejeitado',refunded:'Reembolsado',charged_back:'Estornado'})[s] || s;
async function load() {
  loading.value=true; error.value='';
  try { data.value=(await http.get('/dev/affiliates')).data.data; await loadSales(); }
  catch(e) {error.value=serverError(e);} finally {loading.value=false;}
}
async function findUsers() {
  error.value='';
  if(search.value.trim().length<2) {error.value='Pesquise pelo menos 2 caracteres.';return;}
  try { candidates.value=(await http.get('/dev/affiliates/candidates',{params:{search:search.value.trim()}})).data.data; }
  catch(e) {error.value=serverError(e);}
}
async function submitAffiliate() {
  if(!userId.value||busy.value) return;
  busy.value=true; error.value='';
  try {await ensureCsrf();await http.post('/dev/affiliates',{user_id:Number(userId.value)});notice.value='Afiliado criado.';userId.value=null;candidates.value=[];await load();}
  catch(e){error.value=e.response?.data?.errors?Object.values(e.response.data.errors).flat().join(' '):serverError(e);}
  finally{busy.value=false;}
}
async function submitCoupon() {
  if(busy.value) return;
  busy.value=true;error.value='';
  try {
    await ensureCsrf();
    await http.post('/dev/affiliates/coupons',{
      affiliate_id:Number(form.value.affiliate_id),code:form.value.code.trim().toUpperCase(),
      discount_percent:Number(form.value.discount_percent),plan:form.value.plan||null,
      max_uses:form.value.max_uses?Number(form.value.max_uses):null,
      expires_at:form.value.expires_at?new Date(form.value.expires_at).toISOString():null,
    });
    notice.value='Cupom criado. Já pode ser consultado na contratação.';
    form.value={affiliate_id:'',code:'',discount_percent:10,plan:'',max_uses:'',expires_at:''};
    await load();
  }catch(e){error.value=e.response?.data?.errors?Object.values(e.response.data.errors).flat().join(' '):serverError(e);}
  finally{busy.value=false;}
}
async function toggle(type,item) {
  if(busy.value||!confirm(`${item.is_active?'Desativar':'Reativar'} ${type==='coupon'?'cupom':'afiliado'}? Pedidos antigos não serão alterados.`))return;
  busy.value=true;error.value='';
  try{await ensureCsrf();await http.patch(type==='coupon'?`/dev/affiliates/coupons/${item.id}`:`/dev/affiliates/${item.id}`,{is_active:!item.is_active});notice.value='Status atualizado.';await load();}
  catch(e){error.value=serverError(e);}finally{busy.value=false;}
}
async function loadSales(){
  try {const r=(await http.get('/dev/affiliates/sales',{params:{page:page.value,coupon_id:couponFilter.value||undefined}})).data.data;sales.value=r.data;lastPage.value=r.last_page;}
  catch(e){error.value=serverError(e);}
}
function filterSales(){page.value=1;loadSales();}
onMounted(load);
</script>
<template>
  <section class="affiliate-manager" aria-label="Gestão de afiliados">
    <header class="dev-plan-manager-header"><div><div class="dev-overline">PROGRAMA DE AFILIADOS</div><h2>Afiliados e cupons<span>.</span></h2><p>Vincule um usuário existente a um afiliado, crie cupons e acompanhe atribuições. Receita só aparece após pagamento confirmado.</p></div><button type="button" class="dev-button-outline" :disabled="loading" @click="load">Atualizar</button></header>
    <p v-if="error" class="dev-banner dev-error" role="alert">{{error}}</p><p v-if="notice" class="dev-banner dev-success" role="status">{{notice}}</p>
    <div class="affiliate-kpis"><div><small>Afiliados</small><strong>{{data.summary.affiliates||0}}</strong></div><div><small>Cupons</small><strong>{{data.summary.coupons||0}}</strong></div><div><small>Cadastros com cupom</small><strong>{{data.summary.uses||0}}</strong></div><div><small>Pedidos gerados</small><strong>{{data.summary.orders||0}}</strong></div><div><small>Vendas pagas</small><strong>{{data.summary.paid_sales||0}}</strong></div><div><small>Receita paga</small><strong>{{money(data.summary.paid_revenue_cents)}}</strong></div></div>
    <div class="affiliate-grid">
      <form class="affiliate-card" @submit.prevent="submitAffiliate"><h3>Adicionar afiliado</h3><p>O afiliado deve possuir uma conta ACE ativa. O vínculo não concede acesso DEV.</p><label>Localizar usuário<input v-model.trim="search" placeholder="Nome ou e-mail" minlength="2" maxlength="100" @keyup.enter.prevent="findUsers"/></label><button type="button" class="dev-button-outline" @click="findUsers">Pesquisar usuário</button><label v-if="candidates.length">Selecione a conta<select v-model="userId" required><option :value="null">Selecione...</option><option v-for="u in candidates" :key="u.id" :value="u.id">{{u.name}} — {{u.email}}</option></select></label><p v-else-if="search.length>=2">Nenhum usuário selecionado. Faça a pesquisa.</p><button :disabled="!userId||busy" type="submit" class="affiliate-action">Vincular afiliado</button></form>
      <form class="affiliate-card" @submit.prevent="submitCoupon"><h3>Criar cupom</h3><label>Afiliado<select v-model="form.affiliate_id" required><option value="">Selecione...</option><option v-for="a in data.affiliates.filter(a=>a.is_active && a.user_active)" :key="a.id" :value="a.id">{{a.name}} — {{a.email}}</option></select></label><label>Código<input v-model.trim="form.code" required minlength="4" maxlength="32" placeholder="EX: MARIA10" pattern="[A-Za-z0-9_-]{4,32}"/></label><label>Desconto (%)<input v-model.number="form.discount_percent" type="number" min="1" max="90" step="1" required/></label><label>Plano<select v-model="form.plan"><option value="">Cloud e Pro</option><option value="cloud">Somente Cloud</option><option value="pro">Somente Pro</option></select></label><label>Limite de cadastros (opcional)<input v-model="form.max_uses" type="number" min="1" max="1000000" placeholder="Sem limite"/></label><label>Validade (opcional)<input v-model="form.expires_at" type="datetime-local"/></label><button type="submit" class="affiliate-action" :disabled="busy||!hasAffiliates">Criar cupom</button></form>
    </div>
    <section class="affiliate-card"><h3>Afiliados cadastrados</h3><div class="affiliate-scroll"><table class="dev-table"><thead><tr><th>USUÁRIO</th><th>E-MAIL</th><th>USOS / VENDAS</th><th>RECEITA PAGA</th><th>STATUS</th><th>AÇÃO</th></tr></thead><tbody><tr v-for="a in data.affiliates" :key="a.id"><td>{{a.name}}</td><td>{{a.email}}</td><td>{{a.uses}} / {{a.paid_sales}}</td><td>{{money(a.paid_revenue_cents)}}</td><td>{{a.is_active?'Ativo':'Pausado'}}</td><td><button class="dev-button-outline" @click="toggle('affiliate',a)">{{a.is_active?'Desativar':'Reativar'}}</button></td></tr></tbody></table><p v-if="!data.affiliates.length">Nenhum afiliado cadastrado.</p></div></section>
    <section class="affiliate-card"><h3>Cupons e desempenho</h3><p>Uso = cadastro atribuído; pedidos = cobranças criadas; vendas = Orders aprovadas pelo gateway. Reembolsos não contam como receita paga.</p><div class="affiliate-scroll"><table class="dev-table"><thead><tr><th>CUPOM / AFILIADO</th><th>DESCONTO</th><th>USOS</th><th>PEDIDOS</th><th>VENDAS PAGAS</th><th>RECEITA PAGA</th><th>STATUS</th><th>AÇÃO</th></tr></thead><tbody><tr v-for="c in data.coupons" :key="c.id"><td><strong>{{c.code}}</strong><small>{{c.affiliate_name}} · {{c.plan||'Cloud / Pro'}} · {{when(c.expires_at)}}</small></td><td>{{c.discount_percent}}%</td><td>{{c.uses}}{{c.max_uses?' / '+c.max_uses:''}}</td><td>{{c.orders}}</td><td>{{c.paid_sales}}<small v-if="c.refunded">{{c.refunded}} reembolso(s)</small></td><td>{{money(c.paid_revenue_cents)}}</td><td>{{c.is_active?'Ativo':'Pausado'}}</td><td><button class="dev-button-outline" @click="toggle('coupon',c)">{{c.is_active?'Desativar':'Reativar'}}</button></td></tr></tbody></table><p v-if="!data.coupons.length">Nenhum cupom criado.</p></div></section>
    <section class="affiliate-card"><h3>Pedidos atribuídos</h3><label>Filtrar cupom<select v-model="couponFilter" @change="filterSales"><option value="">Todos</option><option v-for="c in data.coupons" :key="c.id" :value="c.id">{{c.code}}</option></select></label><div class="affiliate-scroll"><table class="dev-table"><thead><tr><th>DATA</th><th>PLANO</th><th>STATUS</th><th>DESCONTO</th><th>VALOR FINAL</th></tr></thead><tbody><tr v-for="s in sales" :key="s.id"><td>{{when(s.created_at)}}</td><td>{{s.plan}} / {{s.cycle}}</td><td>{{statusName(s.status)}}</td><td>{{money(s.discount_cents)}}</td><td>{{money(s.amount_cents)}}</td></tr></tbody></table><p v-if="!sales.length">Nenhum pedido atribuído.</p></div><div class="dev-pagination">Página {{page}} de {{lastPage}} <div><button :disabled="page<=1" @click="page--;loadSales()">Anterior</button><button :disabled="page>=lastPage" @click="page++;loadSales()">Próxima</button></div></div></section>
    <p class="dev-privacy">O módulo não revela dados internos dos workspaces. Com pagamentos pausados, novas vendas pagas permanecerão em zero.</p>
  </section>
</template>
<style scoped>
.affiliate-manager{display:grid;gap:20px}.affiliate-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:12px}.affiliate-kpis>div,.affiliate-card{padding:20px;border:1px solid var(--ace-border,#dadada);border-radius:15px;background:var(--ace-surface,#fff)}.affiliate-kpis small{display:block;opacity:.7;margin-bottom:8px}.affiliate-kpis strong{font-size:24px}.affiliate-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(275px,1fr));gap:15px}.affiliate-card{display:grid;align-content:start;gap:12px;min-width:0}.affiliate-card h3{font-size:18px;font-weight:750}.affiliate-card p{opacity:.8;font-size:13px}.affiliate-card label{display:grid;gap:5px;font-size:13px;font-weight:600}.affiliate-card input,.affiliate-card select{width:100%;min-width:0;border:1px solid #9b9b9b;background:transparent;color:inherit;border-radius:8px;padding:10px}.affiliate-action{padding:12px 16px;background:#ffc734;color:#1a1a1a;border:0;border-radius:10px;font-weight:700;cursor:pointer}.affiliate-action:disabled{opacity:.5;cursor:not-allowed}.affiliate-scroll{overflow-x:auto}.affiliate-scroll table{min-width:680px}.affiliate-scroll td small{display:block;opacity:.7}.affiliate-scroll button{white-space:nowrap}
</style>
