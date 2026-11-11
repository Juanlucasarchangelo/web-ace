<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { Sparkles, Bot, Send, LoaderCircle, Globe2, Users, ShieldCheck, LockKeyhole, RotateCcw, ArrowUpRight, ExternalLink, Check, X, MapPin, WandSparkles, Info, ChevronRight, CircleCheck } from 'lucide-vue-next';
import { aceAiState, sendAceAi, confirmAceAi, cancelAceAi, resetAceAi } from '../lib/aceAiChat';
import '../../css/ace-ai-next.css';

const props = defineProps({
    mode: { type: String, default: 'page' },
    page: { type: String, default: 'dashboard' },
    siteId: { type: Number, default: null },
    dev: { type: Boolean, default: false },
});
const emit = defineEmits(['navigate', 'updated', 'close']);
const draft = ref('');
const messagesEnd = ref(null);
const messagesBox = ref(null);
const field = ref(null);
const titleByPage = {
    dashboard: 'Visão geral', clientes: 'Clientes', sites: 'Sites', cofre: 'Cofre de acessos',
    ia: 'ACE IA', empresas: 'Empresas', equipe: 'Equipe e permissões', atividade: 'Auditoria', nuvem: 'Integrações em nuvem',
    assinatura: 'Plano e pagamento', assinatura_bloqueada: 'Assinatura pendente',
    'dev-tenants': 'Empresas', 'dev-users': 'Todos os usuários', 'dev-issues': 'Problemas e logs', 'dev-audit': 'Ações do DEV', 'dev-plans': 'Planos e preços',
};
const currentPage = computed(() => aceAiState.pages[props.page]?.label || titleByPage[props.page] || props.page);
const suggestions = computed(() => {
    const pagePrompts = {
        clientes: ['Quantos clientes temos?', 'Me explique esta tela'],
        sites: ['Mostre os sites cadastrados', 'Me explique esta tela'],
        cofre: ['Me explique esta tela', 'Quantos acessos temos?'],
        nuvem: ['Quais contas do Drive estão conectadas?', 'Me explique esta tela'],
        equipe: ['Quantas pessoas estão na equipe?', 'Me explique esta tela'],
        atividade: ['Me explique esta tela', 'Quantos sites temos?'],
        assinatura: ['Qual é o meu plano?', 'Quando vence minha assinatura?'],
        assinatura_bloqueada: ['Por que o acesso está bloqueado?', 'Como renovar o plano?'],
        'dev-plans': ['Quais são os preços atuais?', 'Me explique esta tela'],
        'dev-users': ['Quantos usuários temos?', 'Me explique esta tela'],
        'dev-tenants': ['Quantas empresas existem?', 'Me explique esta tela'],
        'dev-issues': ['Quantos problemas estão em aberto?', 'Me explique esta tela'],
        'dev-audit': ['Me explique esta tela', 'Qual é a finalidade da auditoria?'],
    };
    return pagePrompts[props.page] || (props.dev ? ['Me explique esta tela', 'Como funcionam os planos?'] : ['Quantos sites e clientes eu tenho?', 'Me explique esta tela']);
});
const isCompact = computed(() => props.mode === 'floating');
const loading = computed(() => aceAiState.busy);

function scrollToBottom() { nextTick(() => { if (messagesBox.value) messagesBox.value.scrollTo({ top: messagesBox.value.scrollHeight, behavior: 'smooth' }); }); }
watch(() => [aceAiState.messages.length, aceAiState.busy], scrollToBottom);
function focusInput() { nextTick(() => field.value?.focus()); }
function keyDown(event) {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) { event.preventDefault(); void submit(); }
}
async function submit(value = null) {
    const text = (value ?? draft.value).trim();
    if (text === '' || loading.value) return;
    draft.value = '';
    const ok = await sendAceAi(text, { page: props.page, siteId: props.siteId });
    if (!ok) { /* The message was sent, but no result returned: retain no secret in the input. */ }
    scrollToBottom();
}
async function approve(message) {
    const ok = await confirmAceAi(message);
    if (ok) emit('updated');
    scrollToBottom();
}
function openSite(site) {
    const id = Number(site.id);
    if (Number.isSafeInteger(id) && id > 0) emit('navigate', 'sites', id);
}
function cancel(message) { cancelAceAi(message); scrollToBottom(); }
function newConversation() { resetAceAi(); draft.value = ''; focusInput(); }
function fillTemplate() {
    draft.value = 'Nome do cliente: \nDomínio: \nUsuário: \nSenha: \nLink de acesso: \nEndereço: \nCNPJ: ';
    focusInput();
}
defineExpose({ focusInput });
</script>

<template>
    <section class="ace-chat-panel" :class="`ace-chat-${mode}`" aria-label="Assistente ACE IA">
        <div class="ace-chat-topline">
            <div class="ace-chat-identity">
                <div class="ace-chat-emblem"><Sparkles :size="20" :stroke-width="2.2" /></div>
                <div><strong>ACE IA <span>• Copiloto</span></strong><small>{{ aceAiState.model || 'Assistente do workspace' }}</small></div>
            </div>
            <div class="ace-chat-top-actions">
                <span v-if="!isCompact" class="ace-chat-safe-chip"><ShieldCheck :size="13"/> {{ dev ? 'Acesso DEV limitado' : 'Acesso protegido' }}</span>
                <button type="button" class="ace-chat-icon-btn" aria-label="Iniciar nova conversa" title="Nova conversa" :disabled="loading" @click="newConversation"><RotateCcw :size="17"/></button>
                <button v-if="isCompact" type="button" class="ace-chat-icon-btn" aria-label="Fechar chat" title="Fechar" @click="emit('close')"><X :size="18"/></button>
            </div>
        </div>
        <div class="ace-chat-page-context">
            <div class="ace-chat-context-indicator"><MapPin :size="14"/><span>Você está em <strong>{{ currentPage }}</strong></span></div>
            <button type="button" :disabled="loading" @click="submit('Me explique esta tela e o que posso fazer aqui.')">Explicar esta tela <ChevronRight :size="14"/></button>
        </div>
        <div ref="messagesBox" class="ace-chat-messages" role="log" aria-label="Mensagens da ACE IA" aria-live="polite">
            <div v-if="aceAiState.messages.length <= 1" class="ace-chat-welcome">
                <div class="ace-chat-welcome-icon"><WandSparkles :size="25" /></div>
                <h3>O que vamos resolver hoje?</h3>
                <p>{{ dev ? 'Consulte indicadores operacionais, entenda cada tela e confira o catálogo de planos.' : 'Pesquise informações, entenda a tela atual ou prepare um cadastro completo colando os dados em um bloco.' }}</p>
                <div class="ace-chat-quick-prompts">
                    <button v-for="suggestion in suggestions" :key="suggestion" type="button" :disabled="loading" @click="submit(suggestion)">{{ suggestion }} <ArrowUpRight :size="13"/></button>
                </div>
            </div>
            <div v-for="message in aceAiState.messages" :key="message.id" class="ace-chat-line" :class="message.who === 'user' ? 'is-user' : 'is-assistant'">
                <div v-if="message.who !== 'user'" class="ace-chat-bot-avatar"><Bot :size="16" /></div>
                <div class="ace-chat-content">
                    <div class="ace-chat-bubble">{{ message.text }}</div>
                    <div v-if="message.results?.length" class="ace-chat-result-grid">
                        <article v-for="site in message.results" :key="site.id" class="ace-chat-result-card">
                            <Globe2 :size="18"/><div class="ace-chat-result-details"><strong>{{ site.name }}</strong><small>{{ site.client || 'Cliente não informado' }}</small><span>{{ site.domain }}</span>
                                <div class="ace-chat-result-actions"><button type="button" @click="openSite(site)">Abrir ficha <ArrowUpRight :size="12"/></button><a v-if="site.website_url" :href="site.website_url" target="_blank" rel="noopener noreferrer">Visitar <ExternalLink :size="12"/></a></div>
                            </div>
                        </article>
                    </div>
                    <div v-if="message.clients?.length" class="ace-chat-result-grid">
                        <article v-for="client in message.clients" :key="client.id" class="ace-chat-result-card"><Users :size="18"/><div class="ace-chat-result-details"><strong>{{ client.name }}</strong><small>{{ client.sites_count }} site(s)</small><button type="button" class="ace-chat-client-open" @click="emit('navigate','clientes')">Ver clientes <ArrowUpRight :size="12"/></button></div></article>
                    </div>
                    <div v-if="message.preview && Object.keys(message.preview).length" class="ace-chat-preview">
                        <strong class="ace-chat-preview-heading"><Info :size="15"/> Prévia do cadastro</strong>
                        <div v-if="message.preview.client"><span>Cliente</span><strong>{{ message.preview.client }}</strong></div>
                        <div v-if="message.preview.site"><span>Site</span><strong>{{ message.preview.site }}</strong></div>
                        <div v-if="message.preview.document"><span>CPF/CNPJ</span><strong>{{ message.preview.document }}</strong></div>
                        <div v-if="message.preview.address"><span>Endereço</span><strong>{{ message.preview.address }}</strong></div>
                        <div v-if="message.preview.access"><span>Acesso</span><strong>{{ message.preview.access }}</strong></div>
                        <div v-if="message.preview.access_url"><span>URL de acesso</span><strong>{{ message.preview.access_url }}</strong></div>
                    </div>
                    <div v-if="message.confirmation" class="ace-chat-approval">
                        <div class="ace-chat-approval-heading"><LockKeyhole :size="17"/><strong>Autorizar criação</strong></div>
                        <p>{{ message.confirmation.summary }}</p>
                        <div class="ace-chat-approval-actions"><button type="button" class="ace-chat-approve" :disabled="loading" @click="approve(message)"><Check :size="15"/> Confirmar e gravar</button><button type="button" class="ace-chat-cancel" :disabled="loading" @click="cancel(message)">Cancelar</button></div>
                        <small><CircleCheck :size="12"/> Confirmação única · expira em 5 minutos · auditoria ativa</small>
                    </div>
                </div>
            </div>
            <div v-if="loading" class="ace-chat-line is-assistant"><div class="ace-chat-bot-avatar"><Bot :size="16"/></div><div class="ace-chat-bubble ace-chat-busy"><LoaderCircle :size="16" class="ace-chat-rotate"/> Analisando sua solicitação...</div></div>
            <div ref="messagesEnd"></div>
        </div>
        <div class="ace-chat-footer">
            <div v-if="aceAiState.error" class="ace-chat-error" role="alert">{{ aceAiState.error }}</div>
            <div class="ace-chat-input-shell">
                <textarea ref="field" v-model="draft" :disabled="loading" :maxlength="5000" :rows="isCompact ? 2 : 3" aria-label="Mensagem para ACE IA" placeholder="Pergunte ou cole os dados para cadastrar..." @keydown="keyDown"></textarea>
                <button type="button" :disabled="loading || draft.trim().length < 2" class="ace-chat-submit" aria-label="Enviar mensagem" @click="submit()"><LoaderCircle v-if="loading" :size="18" class="ace-chat-rotate"/><Send v-else :size="18"/></button>
            </div>
            <p class="ace-chat-safety-note"><LockKeyhole :size="12"/> {{ dev ? 'O DEV só consulta metadados operacionais; nenhum conteúdo privado é disponibilizado.' : 'Credenciais de cadastros estruturados são tratadas localmente e não enviadas ao Gemini.' }}</p>
        </div>
    </section>
</template>
