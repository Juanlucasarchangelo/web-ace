import { reactive } from 'vue';
import { http } from './http';
import { serverError } from './ace';

/** One in-memory conversation shared by dashboard, full screen and floating assistant. */
export const aceAiState = reactive({
    scope: '', messages: [], busy: false, error: '', engine: 'local', model: '', canWrite: false,
});
let sequence = 0;
const intro = 'Olá! Sou a ACE IA. Posso ajudar com este workspace e com a página que você está usando. Cole os dados de um cliente, site e webmail em linhas separadas para preparar um cadastro seguro.';
function greeting() { return { id: ++sequence, who: 'assistant', text: intro, results: [], clients: [], confirmation: null }; }

export function initializeAceAi(tenantId, userId) {
    const scope = `${userId || 'user'}:${tenantId || 'tenant'}`;
    if (scope === aceAiState.scope) return;
    aceAiState.scope = scope;
    aceAiState.messages = [greeting()];
    aceAiState.error = '';
    aceAiState.engine = 'local';
    aceAiState.model = '';
    aceAiState.canWrite = false;
    void refreshAceAiStatus(scope);
}
export async function refreshAceAiStatus(scope = aceAiState.scope) {
    try {
        const { data } = await http.get('/ai/status');
        if (scope !== aceAiState.scope) return;
        aceAiState.engine = data.data?.engine || 'local';
        aceAiState.model = data.data?.model || '';
        aceAiState.canWrite = !!data.data?.can_write;
    } catch { /* Network error will be shown when sending. */ }
}
export function clearAceAiSession() {
    aceAiState.scope = '';
    aceAiState.messages = [];
    aceAiState.error = '';
    aceAiState.engine = 'local';
    aceAiState.model = '';
    aceAiState.canWrite = false;
}
export function resetAceAi() {
    if (aceAiState.busy) return;
    aceAiState.messages = [greeting()];
    aceAiState.error = '';
}
function privateBlock(text) {
    return /(?:^|\n)\s*(?:senha|password|pass|pwd|token|api.?key|chave|secret|cnpj|cpf|documento)\s*:/im.test(text)
        || /\b\d{14}\b|\b\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2}\b/.test(text)
        || (text.includes('\n') && /(?:[a-z0-9-]+\.)+[a-z]{2,}/i.test(text)
            && /(?:usu[aá]rio|login|endere[cç]o|link de acesso|cnpj|cpf)/i.test(text));
}
function safeMessage(text) {
    if (privateBlock(text)) return '🔒 Bloco de cadastro protegido enviado para processamento local. Dados sensíveis ocultos desta conversa.';
    return text;
}
function pushReply(reply) {
    if (reply.model) aceAiState.model = reply.model;
    aceAiState.engine = reply.engine || aceAiState.engine;
    // Old confirmations must not remain actionable once the user starts a new draft.
    if (reply.confirmation) aceAiState.messages.forEach(m => { if (m.confirmation) m.confirmation = null; });
    aceAiState.messages.push({
        id: ++sequence, who: 'assistant', text: String(reply.text || 'Não foi possível concluir a solicitação.'),
        results: Array.isArray(reply.results) ? reply.results : [],
        clients: Array.isArray(reply.clients) ? reply.clients : [],
        preview: reply.preview || null,
        confirmation: reply.confirmation || null,
    });
}
export async function sendAceAi(raw, context = {}) {
    if (aceAiState.busy) return false;
    const prompt = (raw || '').trim();
    if (prompt.length < 2 || prompt.length > 5000) {
        aceAiState.error = 'Escreva uma mensagem entre 2 e 5.000 caracteres.';
        return false;
    }
    const before = aceAiState.scope;
    aceAiState.error = '';
    const history = aceAiState.messages.filter(m => m.who === 'user' && !m.private)
        .slice(-7).map(m => ({ role: 'user', text: String(m.text).slice(0, 600) }));
    aceAiState.messages.push({ id: ++sequence, who: 'user', text: safeMessage(prompt), private: privateBlock(prompt), results: [], clients: [], confirmation: null });
    aceAiState.busy = true;
    try {
        const { data } = await http.post('/ai/chat', {
            message: prompt, history, context: {
                page: context.page || 'dashboard',
                site_id: context.page === 'sites' ? context.siteId || null : null,
            },
        }, { timeout: 125000 });
        if (before === aceAiState.scope) pushReply(data.data || {});
        return true;
    } catch (error) {
        if (before === aceAiState.scope) aceAiState.error = serverError(error) || 'Não foi possível consultar a ACE IA.';
        return false;
    } finally {
        aceAiState.busy = false;
    }
}
export async function confirmAceAi(message) {
    if (aceAiState.busy || !message?.confirmation?.token) return false;
    const token = message.confirmation.token;
    const before = aceAiState.scope;
    aceAiState.busy = true;
    aceAiState.error = '';
    try {
        const { data } = await http.post('/ai/confirm', { token }, { timeout: 30000 });
        if (before === aceAiState.scope) { message.confirmation = null; pushReply(data.data || {}); }
        return true;
    } catch (error) {
        if (before === aceAiState.scope) { aceAiState.error = serverError(error) || 'Confirmação não concluída.'; message.confirmation = null; }
        return false;
    } finally {
        aceAiState.busy = false;
    }
}
export function cancelAceAi(message) {
    if (!message?.confirmation) return;
    message.confirmation = null;
    aceAiState.messages.push({ id: ++sequence, who: 'assistant', text: 'Cadastro cancelado. Nenhuma alteração foi realizada.', results: [], clients: [], confirmation: null });
}
