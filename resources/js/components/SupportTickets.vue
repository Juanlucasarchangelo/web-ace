<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { LifeBuoy, Plus, Send, ArrowLeft, RefreshCw, CheckCircle2, CircleAlert, MessageSquare, Clock3 } from 'lucide-vue-next';
import { http } from '../lib/http';
import { serverError } from '../lib/ace';

const props = defineProps({ mode: { type: String, default: 'member' } });
const isDev = computed(() => props.mode === 'dev');
const endpoint = computed(() => isDev.value ? '/dev/support/tickets' : '/support/tickets');
const loading = ref(false), busy = ref(false), error = ref(''), notice = ref('');
const tickets = ref([]), selected = ref(null), newForm = ref(false), status = ref('all'), search = ref('');
const pager = reactive({ page: 1, last: 1, total: 0 });
const form = reactive({ subject: '', category: 'question', priority: 'normal', message: '' });
const replyBody = ref('');
const categories = { question: 'Dúvida', billing: 'Cobrança', technical: 'Problema técnico', request: 'Solicitação', other: 'Outro' };
const statuses = { open: 'Aberto', answered: 'Respondido pelo DEV', waiting_user: 'Aguardando usuário', closed: 'Encerrado' };
const priorities = { normal: 'Normal', high: 'Alta' };
const fmtDate = value => value ? new Date(value).toLocaleString('pt-BR') : '—';
function fail(e) { error.value = serverError(e); notice.value = ''; }
async function load() {
  loading.value = true; error.value = '';
  try {
    const params = { page: pager.page };
    if (isDev.value) { params.status = status.value; if (search.value.trim()) params.search = search.value.trim(); }
    const { data } = await http.get(endpoint.value, { params });
    tickets.value = data.data.data || []; pager.last = data.data.last_page || 1; pager.total = data.data.total || 0;
  } catch (e) { fail(e); } finally { loading.value = false; }
}
async function open(ticket) {
  busy.value = true; error.value = '';
  try { selected.value = (await http.get(`${endpoint.value}/${ticket.id}`)).data.data; replyBody.value = ''; newForm.value = false; }
  catch (e) { fail(e); } finally { busy.value = false; }
}
async function create() {
  if (busy.value) return; busy.value = true; error.value = ''; notice.value = '';
  try {
    const { data } = await http.post(endpoint.value, form);
    newForm.value = false; Object.assign(form, { subject:'', category:'question', priority:'normal', message:'' });
    notice.value = 'Chamado aberto com sucesso.';
    await load(); await open(data.data);
  } catch (e) { fail(e); } finally { busy.value = false; }
}
async function reply() {
  if (busy.value || replyBody.value.trim().length < 2 || !selected.value) return;
  busy.value = true; error.value = ''; notice.value = '';
  try { await http.post(`${endpoint.value}/${selected.value.id}/reply`, { message: replyBody.value.trim() });
    replyBody.value = ''; notice.value = 'Resposta registrada.';
    const id=selected.value.id; await open({ id }); await load(); }
  catch (e) { fail(e); } finally { busy.value = false; }
}
async function close() {
  if (busy.value || !selected.value || !window.confirm('Encerrar este chamado? Ele poderá ser reaberto com uma nova resposta.')) return;
  busy.value = true; error.value = '';
  try { await http.post(`${endpoint.value}/${selected.value.id}/close`); notice.value = 'Chamado encerrado.'; await open(selected.value); await load(); }
  catch(e){ fail(e); } finally {busy.value=false;}
}
async function saveDev() {
  if (!selected.value || busy.value) return;
  busy.value=true; error.value='';
  try { await http.patch(`${endpoint.value}/${selected.value.id}`, { status:selected.value.status, priority:selected.value.priority });
    notice.value='Status e prioridade atualizados.'; await open(selected.value); await load(); }
  catch(e){fail(e);} finally {busy.value=false;}
}
function resetSearch(){pager.page=1;load();}
function changePage(delta){pager.page=Math.max(1,Math.min(pager.last,pager.page+delta));load();}
function back(){selected.value=null;newForm.value=false;load();}
onMounted(load);
</script>
<template>
  <section class="ace-support">
    <header class="ace-support-head">
      <div><div class="ace-support-eyebrow"><LifeBuoy :size="15"/> CENTRAL DE ATENDIMENTO</div>
        <h2>{{isDev ? 'Chamados de suporte' : 'Meus chamados'}}<span>.</span></h2>
        <p>{{isDev ? 'Atenda solicitações com histórico e isolamento por empresa.' : 'Fale com a equipe ACE e acompanhe as respostas em um só lugar.'}}</p></div>
      <div class="ace-support-head-actions"><button type="button" class="ace-support-btn secondary" :disabled="loading" @click="selected||newForm?back():load()"><RefreshCw :size="15"/> Atualizar</button>
        <button v-if="!isDev&&!selected&&!newForm" type="button" class="ace-support-btn primary" @click="newForm=true"><Plus :size="16"/> Novo chamado</button></div>
    </header>
    <p v-if="error" role="alert" class="ace-support-alert error"><CircleAlert :size="18"/>{{error}}</p>
    <p v-if="notice" role="status" class="ace-support-alert success"><CheckCircle2 :size="18"/>{{notice}}</p>
    <div v-if="newForm" class="ace-support-card">
      <button class="ace-support-link" type="button" @click="back"><ArrowLeft :size="15"/> Voltar aos chamados</button>
      <h3>Abrir um chamado</h3><p class="ace-support-muted">Descreva seu problema. Por segurança, não inclua senhas ou tokens de acesso.</p>
      <form class="ace-support-form" @submit.prevent="create">
        <label>Assunto<input v-model.trim="form.subject" minlength="5" maxlength="180" required placeholder="Ex.: Dúvida sobre a renovação"/></label>
        <div class="ace-support-two"><label>Categoria<select v-model="form.category"><option v-for="(name,id) in categories" :key="id" :value="id">{{name}}</option></select></label>
          <label>Prioridade<select v-model="form.priority"><option v-for="(name,id) in priorities" :key="id" :value="id">{{name}}</option></select></label></div>
        <label>Descrição<textarea v-model.trim="form.message" required minlength="10" maxlength="10000" rows="7" placeholder="Explique o que aconteceu e como podemos ajudar."/></label>
        <button type="submit" :disabled="busy" class="ace-support-btn primary"><Send :size="16"/> {{busy?'Enviando...':'Abrir chamado'}}</button>
      </form>
    </div>
    <div v-else-if="selected" class="ace-support-card">
      <button type="button" class="ace-support-link" @click="back"><ArrowLeft :size="15"/> Voltar aos chamados</button>
      <div class="ace-support-ticket-heading"><div><small>#{{selected.id.slice(0,8).toUpperCase()}}</small><h3>{{selected.subject}}</h3><p>{{categories[selected.category]||selected.category}} · Aberto em {{fmtDate(selected.created_at)}} <span v-if="selected.tenant">· {{selected.tenant.name}}</span></p></div><span class="ace-support-chip" :class="selected.status">{{statuses[selected.status]||selected.status}}</span></div>
      <div v-if="isDev" class="ace-support-controls"><label>Status<select v-model="selected.status"><option v-for="(name,id) in statuses" :key="id" :value="id">{{name}}</option></select></label><label>Prioridade<select v-model="selected.priority"><option v-for="(name,id) in priorities" :key="id" :value="id">{{name}}</option></select></label><button type="button" class="ace-support-btn secondary" :disabled="busy" @click="saveDev">Salvar status</button></div>
      <div class="ace-support-thread"><article v-for="message in selected.messages||[]" :key="message.id" class="ace-support-message" :class="message.sender_role==='dev'?'from-dev':'from-user'"><div class="ace-support-message-meta"><strong>{{message.sender_role==='dev'?'Equipe ACE':(message.author?.name||'Usuário')}}</strong><small>{{fmtDate(message.created_at)}}</small></div><p>{{message.body}}</p></article></div>
      <form class="ace-support-form ace-support-reply" @submit.prevent="reply"><label>Responder<textarea v-model.trim="replyBody" minlength="2" maxlength="10000" required rows="4" placeholder="Escreva sua resposta..."/></label><div class="ace-support-actions"><button v-if="!isDev&&selected.status!=='closed'" type="button" class="ace-support-btn secondary" :disabled="busy" @click="close">Encerrar chamado</button><button type="submit" :disabled="busy || replyBody.length<2" class="ace-support-btn primary"><Send :size="15"/> {{busy?'Salvando...':'Enviar resposta'}}</button></div></form>
    </div>
    <div v-else class="ace-support-card">
      <div v-if="isDev" class="ace-support-filters"><label>Filtrar por status<select v-model="status" @change="resetSearch"><option value="all">Todos os chamados</option><option v-for="(name,id) in statuses" :key="id" :value="id">{{name}}</option></select></label><label>Buscar<input v-model.trim="search" maxlength="100" placeholder="Empresa ou assunto" @keyup.enter="resetSearch"/></label><button type="button" class="ace-support-btn secondary" @click="resetSearch">Buscar</button></div>
      <div v-if="loading" class="ace-support-empty">Carregando chamados...</div>
      <div v-else-if="!tickets.length" class="ace-support-empty"><MessageSquare :size="29"/><h3>Nenhum chamado encontrado</h3><p>{{isDev?'Novas solicitações aparecerão aqui.':'Abra um chamado quando precisar de ajuda.'}}</p></div>
      <button v-for="ticket in tickets" :key="ticket.id" type="button" class="ace-support-ticket" @click="open(ticket)"><div class="ace-support-ticket-icon"><MessageSquare :size="19"/></div><div class="ace-support-ticket-description"><strong>{{ticket.subject}}</strong><span>#{{ticket.id.slice(0,8).toUpperCase()}} · {{categories[ticket.category]||ticket.category}} <template v-if="isDev">· {{ticket.tenant?.name||'Empresa'}}</template></span><small><Clock3 :size="12"/> {{fmtDate(ticket.updated_at)}} · {{ticket.creator?.name||'Usuário'}}</small></div><div class="ace-support-ticket-state"><span class="ace-support-chip" :class="ticket.status">{{statuses[ticket.status]||ticket.status}}</span><small>{{priorities[ticket.priority]}}</small></div></button>
      <div v-if="pager.last>1" class="ace-support-pagination"><button type="button" @click="changePage(-1)" :disabled="pager.page<=1">Anterior</button><span>Página {{pager.page}} de {{pager.last}}</span><button type="button" @click="changePage(1)" :disabled="pager.page>=pager.last">Próxima</button></div>
    </div>
  </section>
</template>
