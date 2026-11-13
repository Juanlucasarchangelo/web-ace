<script setup>
import { computed, nextTick, onMounted, reactive, ref } from 'vue';
import { LifeBuoy, Plus, Send, ArrowLeft, RefreshCw, CheckCircle2, CircleAlert, MessageSquare, Clock3, Building2, UserRound, CalendarDays, Tag, Flag, RotateCcw, ShieldCheck, ChevronRight, Info } from 'lucide-vue-next';
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
const draftStatus = ref('open');
const draftPriority = ref('normal');
const conversationScroll = ref(null);
const hasChanges = computed(() => !!selected.value && (draftStatus.value !== selected.value.status || draftPriority.value !== selected.value.priority));
const totalMessages = computed(() => selected.value?.messages?.length ?? 0);
const lastInteraction = computed(() => selected.value?.last_reply_at || selected.value?.updated_at || selected.value?.created_at);
function ticketCode(ticket) { return ticket?.id ? String(ticket.id).slice(0,8).toUpperCase() : '—'; }
function fmtShortDate(value) { return value ? new Date(value).toLocaleDateString('pt-BR', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }
async function scrollConversationToLatest() {
  await nextTick();
  if (conversationScroll.value) conversationScroll.value.scrollTop = conversationScroll.value.scrollHeight;
}

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
  try {
    selected.value = (await http.get(`${endpoint.value}/${ticket.id}`)).data.data;
    draftStatus.value = selected.value.status;
    draftPriority.value = selected.value.priority;
    replyBody.value = '';
    newForm.value = false;
    await scrollConversationToLatest();
  }
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
  if (!selected.value || busy.value || !hasChanges.value) return;
  if (draftStatus.value === 'closed' && selected.value.status !== 'closed'
    && !window.confirm('Encerrar este chamado? O encerramento enviará uma notificação conforme as regras configuradas.')) return;
  busy.value = true; error.value = ''; notice.value = '';
  try {
    const unsentReply = replyBody.value;
    await http.patch(`${endpoint.value}/${selected.value.id}`, { status: draftStatus.value, priority: draftPriority.value });
    notice.value = 'Alterações do chamado salvas.';
    await open(selected.value);
    replyBody.value = unsentReply;
    await load();
  } catch (e) { fail(e); } finally { busy.value = false; }
}
function discardChanges() {
  if (!selected.value) return;
  draftStatus.value = selected.value.status;
  draftPriority.value = selected.value.priority;
}
function resetSearch(){pager.page=1;load();}
function changePage(delta){pager.page=Math.max(1,Math.min(pager.last,pager.page+delta));load();}
function back(){selected.value=null;newForm.value=false;load();}
onMounted(load);
</script>
<template>
  <section class="ace-support">
    <header v-if="!isDev" class="ace-support-head">
      <div><div class="ace-support-eyebrow"><LifeBuoy :size="15"/> CENTRAL DE ATENDIMENTO</div>
        <h2>{{isDev ? 'Chamados de suporte' : 'Meus chamados'}}<span>.</span></h2>
        <p>{{isDev ? 'Atenda solicitações com histórico e isolamento por empresa.' : 'Fale com a equipe ACE e acompanhe as respostas em um só lugar.'}}</p></div>
      <div class="ace-support-head-actions"><button type="button" class="ace-support-btn secondary" :disabled="loading" @click="selected||newForm?back():load()"><RefreshCw :size="15"/> Atualizar</button>
        <button v-if="!isDev&&!selected&&!newForm" type="button" class="ace-support-btn primary" @click="newForm=true"><Plus :size="16"/> Novo chamado</button></div>
    </header>
    <div v-if="isDev && !selected && !newForm" class="ace-support-overview" aria-label="Resumo de chamados">
      <div class="ace-support-summary"><span>Chamados encontrados</span><strong>{{pager.total}}</strong><small>Na consulta atual</small></div>
      <div class="ace-support-summary"><span>Exibidos nesta página</span><strong>{{tickets.length}}</strong><small>Use os filtros para localizar</small></div>
      <div class="ace-support-overview-tip"><MessageSquare :size="19"/><div><strong>Central de atendimento</strong><p>Selecione um chamado para visualizar o histórico e responder com segurança.</p></div></div>
    </div>
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
    <div v-else-if="selected" class="ace-ticket-workspace" data-ace-detail-layout="atendimento-v3">
      <nav class="ace-ticket-nav" aria-label="Navegação do chamado">
        <button type="button" class="ace-ticket-back" @click="back"><ArrowLeft :size="16"/> Todos os chamados</button>
        <ChevronRight :size="13" class="ace-ticket-separator" aria-hidden="true"/>
        <span class="ace-ticket-nav-ref">#{{ticketCode(selected)}}</span>
      </nav>

      <section class="ace-ticket-intro" aria-label="Resumo do chamado">
        <div class="ace-ticket-intro-main">
          <div class="ace-ticket-idline">
            <span class="ace-ticket-id">CHAMADO #{{ticketCode(selected)}}</span>
            <span class="ace-support-chip" :class="selected.status">{{statuses[selected.status]||selected.status}}</span>
          </div>
          <h3>{{selected.subject}}</h3>
          <div class="ace-ticket-meta">
            <span><Tag :size="14"/> {{categories[selected.category]||selected.category}}</span>
            <span v-if="selected.tenant"><Building2 :size="14"/> {{selected.tenant.name}}</span>
            <span><CalendarDays :size="14"/> {{fmtDate(selected.created_at)}}</span>
          </div>
        </div>
        <div class="ace-ticket-intro-side">
          <span class="ace-ticket-mini-label">PRIORIDADE</span>
          <strong class="ace-ticket-priority" :class="selected.priority"><Flag :size="15"/> {{priorities[selected.priority]||selected.priority}}</strong>
        </div>
      </section>

      <div class="ace-ticket-layout">
        <section class="ace-ticket-chat" aria-label="Conversa do chamado">
          <div class="ace-ticket-chat-head">
            <div class="ace-ticket-chat-heading"><span class="ace-ticket-chat-icon"><MessageSquare :size="18"/></span><div><h4>Conversa</h4><p>Mensagens entre o cliente e a equipe ACE</p></div></div>
            <span class="ace-ticket-count">{{totalMessages}} {{totalMessages === 1 ? 'mensagem' : 'mensagens'}}</span>
          </div>
          <div ref="conversationScroll" class="ace-ticket-messages" role="log" aria-live="polite" aria-relevant="additions text">
            <div v-if="totalMessages" class="ace-ticket-start-marker"><span>Início do atendimento · {{fmtShortDate(selected.created_at)}}</span></div>
            <article v-for="(message,index) in selected.messages||[]" :key="message.id" class="ace-ticket-msg" :class="message.sender_role==='dev'?'is-team':'is-customer'">
              <div class="ace-ticket-msg-avatar" aria-hidden="true">{{(message.sender_role==='dev'?'A':(message.author?.name||'C')).slice(0,1).toUpperCase()}}</div>
              <div class="ace-ticket-msg-content">
                <div class="ace-ticket-msg-meta">
                  <strong>{{message.sender_role==='dev'?'Equipe ACE':(message.author?.name||'Cliente')}}</strong>
                  <span v-if="index===0" class="ace-ticket-opening">Abertura</span>
                  <time :datetime="message.created_at">{{fmtDate(message.created_at)}}</time>
                </div>
                <div class="ace-ticket-msg-bubble">{{message.body}}</div>
              </div>
            </article>
            <div v-if="!totalMessages" class="ace-ticket-chat-empty"><MessageSquare :size="24"/><strong>Conversa sem mensagens</strong><p>As interações deste chamado aparecerão aqui.</p></div>
          </div>
          <div class="ace-ticket-composer">
            <div class="ace-ticket-composer-header"><div><strong>Responder ao chamado</strong><small>{{selected.status==='closed' ? 'Uma resposta reabrirá o atendimento.' : 'Sua mensagem ficará registrada no histórico.'}}</small></div><span class="ace-ticket-compose-indicator"><span/> Mensagem</span></div>
            <form @submit.prevent="reply" class="ace-ticket-compose-form">
              <label for="ace-ticket-message" class="ace-ticket-sr-only">Sua resposta</label>
              <textarea id="ace-ticket-message" v-model="replyBody" :disabled="busy" minlength="2" maxlength="10000" required rows="4" placeholder="Escreva sua resposta para o chamado..." @keydown.ctrl.enter.prevent="reply" @keydown.meta.enter.prevent="reply"/>
              <div class="ace-ticket-compose-foot">
                <div class="ace-ticket-compose-guidance"><ShieldCheck :size="15"/><span>Não inclua senhas ou tokens. <span class="ace-ticket-shortcut">Ctrl + Enter para enviar.</span></span></div>
                <div class="ace-ticket-compose-actions"><span class="ace-ticket-length">{{replyBody.length}} / 10.000</span><button type="submit" class="ace-support-btn primary" :disabled="busy || replyBody.trim().length<2"><Send :size="16"/> {{busy?'Enviando...':'Enviar resposta'}}</button></div>
              </div>
            </form>
          </div>
        </section>

        <aside class="ace-ticket-sidebar" aria-label="Informações e gestão do chamado">
          <section class="ace-ticket-side-card">
            <div class="ace-ticket-card-heading"><Info :size="16"/><h4>Informações do chamado</h4></div>
            <dl class="ace-ticket-facts">
              <div><dt><UserRound :size="14"/> Solicitante</dt><dd>{{selected.creator?.name || 'Usuário'}}</dd></div>
              <div v-if="selected.tenant"><dt><Building2 :size="14"/> Empresa</dt><dd>{{selected.tenant.name}}</dd></div>
              <div><dt><Tag :size="14"/> Categoria</dt><dd>{{categories[selected.category]||selected.category}}</dd></div>
              <div><dt><CalendarDays :size="14"/> Abertura</dt><dd>{{fmtDate(selected.created_at)}}</dd></div>
              <div><dt><Clock3 :size="14"/> Última interação</dt><dd>{{fmtDate(lastInteraction)}}</dd></div>
            </dl>
          </section>

          <section v-if="isDev" class="ace-ticket-side-card ace-ticket-manage">
            <div class="ace-ticket-card-heading"><Flag :size="16"/><h4>Gerenciar atendimento</h4></div>
            <p class="ace-ticket-manage-caption">Atualize a situação e a prioridade, sem perder o histórico.</p>
            <div class="ace-ticket-manage-form">
              <label for="ace-ticket-status">Situação do chamado</label>
              <select id="ace-ticket-status" v-model="draftStatus" :disabled="busy"><option v-for="(name,id) in statuses" :key="id" :value="id">{{name}}</option></select>
              <label for="ace-ticket-priority">Prioridade</label>
              <select id="ace-ticket-priority" v-model="draftPriority" :disabled="busy"><option v-for="(name,id) in priorities" :key="id" :value="id">{{name}}</option></select>
              <p v-if="draftStatus==='closed' && selected.status!=='closed'" class="ace-ticket-closure-hint"><CircleAlert :size="14"/> O encerramento gera uma notificação por e-mail.</p>
              <div class="ace-ticket-manage-actions"><button type="button" class="ace-support-btn secondary" :disabled="busy || !hasChanges" @click="discardChanges"><RotateCcw :size="14"/> Descartar</button><button type="button" class="ace-support-btn primary" :disabled="busy || !hasChanges" @click="saveDev">{{busy?'Salvando...':'Salvar alterações'}}</button></div>
              <div v-if="hasChanges" class="ace-ticket-unsaved" role="status"><span/> Você tem alterações não salvas.</div>
            </div>
          </section>

          <section v-else class="ace-ticket-side-card ace-ticket-help-card">
            <div class="ace-ticket-card-heading"><ShieldCheck :size="16"/><h4>Acompanhamento</h4></div>
            <p>Você pode continuar a conversa por aqui. Cada resposta fica registrada neste chamado.</p>
            <button v-if="selected.status!=='closed'" type="button" class="ace-support-btn secondary ace-ticket-close" :disabled="busy" @click="close"><CheckCircle2 :size="16"/> Encerrar chamado</button>
            <span v-else class="ace-ticket-closed-note"><CheckCircle2 :size="16"/> Este chamado foi encerrado.</span>
          </section>
        </aside>
      </div>
    </div>
    <div v-else class="ace-support-card ace-support-list-card">
      <div v-if="isDev" class="ace-support-filters"><label>Filtrar por status<select v-model="status" @change="resetSearch"><option value="all">Todos os chamados</option><option v-for="(name,id) in statuses" :key="id" :value="id">{{name}}</option></select></label><label>Buscar<input v-model.trim="search" maxlength="100" placeholder="Empresa ou assunto" @keyup.enter="resetSearch"/></label><button type="button" class="ace-support-btn secondary" @click="resetSearch">Buscar</button></div>
      <div v-if="isDev" class="ace-support-list-heading"><strong>Solicitações</strong><span>{{pager.total}} registro(s)</span></div><div v-if="loading" class="ace-support-empty">Carregando chamados...</div>
      <div v-else-if="!tickets.length" class="ace-support-empty"><MessageSquare :size="29"/><h3>Nenhum chamado encontrado</h3><p>{{isDev?'Novas solicitações aparecerão aqui.':'Abra um chamado quando precisar de ajuda.'}}</p></div>
      <button v-for="ticket in tickets" :key="ticket.id" type="button" class="ace-support-ticket" :aria-label="`Abrir chamado ${ticket.subject}`" @click="open(ticket)"><div class="ace-support-ticket-icon"><MessageSquare :size="19"/></div><div class="ace-support-ticket-description"><strong>{{ticket.subject}}</strong><span>#{{ticket.id.slice(0,8).toUpperCase()}} · {{categories[ticket.category]||ticket.category}} <template v-if="isDev">· {{ticket.tenant?.name||'Empresa'}}</template></span><small><Clock3 :size="12"/> {{fmtDate(ticket.updated_at)}} · {{ticket.creator?.name||'Usuário'}}</small></div><div class="ace-support-ticket-state"><span class="ace-support-chip" :class="ticket.status">{{statuses[ticket.status]||ticket.status}}</span><small>{{priorities[ticket.priority]}}</small></div></button>
      <div v-if="pager.last>1" class="ace-support-pagination"><button type="button" @click="changePage(-1)" :disabled="pager.page<=1">Anterior</button><span>Página {{pager.page}} de {{pager.last}}</span><button type="button" @click="changePage(1)" :disabled="pager.page>=pager.last">Próxima</button></div>
    </div>
  </section>
</template>
