<script setup>
import { onMounted, reactive, ref, watch, computed } from 'vue';
import { Plus, Search, Globe2, Pencil, Trash2, X, Save, ChevronLeft, ChevronRight, KeyRound, Eye, EyeOff, Copy, LockKeyhole, ExternalLink, CircleAlert } from 'lucide-vue-next';
import { http } from '../lib/http';
import { getAllClients, serverError, categoryNames, siteStatus } from '../lib/ace';
const props = defineProps({ canEdit: { type: Boolean, default: false }, initialSiteId: { type: Number, default: null } });
const list = ref([]), clients = ref([]), search = ref(''), filter = ref(''), page = ref(1), pages = ref(1), listError = ref(''), loading = ref(false);
const dialog = ref(false), tab = ref('main'), saving = ref(false), error = ref(''), accessDialog = ref(false), accessError = ref(''), savingAccess = ref(false);
const secretVisible = ref({}), registryVisible = ref(false), registryValue = ref(''), secretShown = ref('');
const mainEmpty = () => ({ client_id:null, name:'', domain:'', status:'active', online_at:'', hosting_provider:'', dns_primary:'', dns_secondary:'', ftp_host:'', control_panel_url:'', store_url:'', social_links:{ drive:'',youtube:'',facebook:'',linkedin:'',gmail:'',instagram:'',linktree:'',additional:'' }, notes:'', registro_br:undefined, accesses:[], has_registry:false });
const accessEmpty = () => ({ category:'email', label:'', service:'', url:'', username:'', secret:'', notes:'' });
const form = reactive(mainEmpty()), accessForm = reactive(accessEmpty());
const sections = [{id:'main',name:'Geral'}, {id:'infra',name:'Hospedagem & DNS'}, {id:'store',name:'Loja & redes'}, {id:'access',name:'Acessos & senhas'}];
const catItems = Object.entries(categoryNames).map(([value,title])=>({value,title}));
const fieldsMain = [{key:'name',label:'Nome do site'}, {key:'domain',label:'Domínio (sem https://)'}, {key:'online_at',label:'Data do site no ar',type:'date'}];
const fieldsInfra = [{key:'hosting_provider',label:'Local de hospedagem'}, {key:'dns_primary',label:'DNS primário'}, {key:'dns_secondary',label:'DNS secundário'}, {key:'ftp_host',label:'Endereço FTP'}, {key:'control_panel_url',label:'Painel de controle (URL)'}];
const socialFields = [{key:'drive',label:'Google Drive (URL)'},{key:'youtube',label:'YouTube (URL)'},{key:'facebook',label:'Facebook (URL)'},{key:'linkedin',label:'LinkedIn (URL)'},{key:'gmail',label:'Gmail / conta'},{key:'instagram',label:'Instagram / perfil'},{key:'linktree',label:'Linktree (URL)'}];
const canAddAccess = computed(()=>props.canEdit && !!form.id);
// Credenciais ficam no cofre criptografado; não são campos em JSON social_links.
const quickServices = [
    { key:'store', title:'Loja virtual', category:'store', service:'Loja virtual' },
    { key:'drive', title:'Google Drive', category:'social', service:'Google Drive' },
    { key:'youtube', title:'YouTube', category:'social', service:'YouTube' },
    { key:'facebook', title:'Facebook', category:'social', service:'Facebook' },
    { key:'linkedin', title:'LinkedIn', category:'social', service:'LinkedIn' },
    { key:'gmail', title:'Gmail', category:'email', service:'Gmail' },
    { key:'instagram', title:'Instagram', category:'social', service:'Instagram' },
    { key:'linktree', title:'Linktree', category:'social', service:'Linktree' },
];
function quickItems(service) {
    return (form.accesses || []).filter(a =>
        (a.service || '').trim().toLocaleLowerCase('pt-BR') === service.service.toLocaleLowerCase('pt-BR') ||
        (a.label || '').trim().toLocaleLowerCase('pt-BR') === service.service.toLocaleLowerCase('pt-BR')
    );
}
function quickAdd(service) {
    accessOpen();
    Object.assign(accessForm, { category:service.category, label:service.title,
        service:service.service,
        url: service.key === 'store' ? (form.store_url || '') : (form.social_links?.[service.key] || '') });
}
function quickEdit(item) { accessOpen(item); }
const otherSocialCredentials = computed(() => (form.accesses || []).filter(a =>
    ['social','store','email'].includes(a.category) &&
    !quickServices.some(service => quickItems(service).some(item => item.id === a.id))
));

let timeout;
async function load() {
    loading.value=true; listError.value='';
    try { const {data} = await http.get('/sites',{params:{search:search.value,status:filter.value,page:page.value}}); list.value=data.data.data; pages.value=data.data.last_page; }
    catch(e){listError.value=serverError(e)} finally{loading.value=false;}
}
watch([search,filter],()=>{page.value=1;clearTimeout(timeout);timeout=setTimeout(load,280)});
watch(page,load);
watch(dialog,visible=>{ if(!visible) {secretVisible.value={};registryValue.value='';registryVisible.value=false;accessDialog.value=false;} });
watch(accessDialog,visible=>{if(!visible) secretShown.value='';});
onMounted(async()=>{await load();try{clients.value=await getAllClients()}catch(e){listError.value=serverError(e)} if(props.initialSiteId) await open({id:props.initialSiteId});});
const clean = () => { const payload={}; for(const k of Object.keys(mainEmpty())) {if(['accesses','has_registry','registro_br'].includes(k))continue; payload[k]=form[k] === '' ? null : form[k];} if(form.registro_br!==undefined)payload.registro_br=form.registro_br; payload.name=form.name;payload.domain=form.domain;payload.status=form.status; return payload;};
async function open(site=null) {
    error.value='';Object.assign(form,mainEmpty());delete form.id; tab.value='main';registryValue.value='';registryVisible.value=false;secretVisible.value={};
    if(site) {try {const {data}=await http.get(`/sites/${site.id}`);Object.assign(form,data.data);form.social_links={...mainEmpty().social_links,...(data.data.social_links||{})};}catch(e){listError.value=serverError(e);return;}}
    dialog.value=true;
}
async function save() {
    if(!props.canEdit)return;
    saving.value=true;error.value='';
    const wasNew = !form.id;
    try {
        const {data}=form.id?await http.put(`/sites/${form.id}`,clean()):await http.post('/sites',clean());
        // After creating, keep dialog open so access records can be added immediately.
        const id=data.data.id; const refreshed=(await http.get(`/sites/${id}`)).data.data;
        Object.assign(form,refreshed);form.social_links={...mainEmpty().social_links,...(refreshed.social_links||{})};form.registro_br=undefined;
        if (!registryVisible.value) registryValue.value='';
        await load();
        if (wasNew) tab.value='store';
    } catch(e){error.value=serverError(e)} finally{saving.value=false;}
}
async function remove() {
    if(!form.id || !confirm(`Excluir o site ${form.domain} e TODOS os acessos vinculados? Essa operação é permanente.`))return;
    try{await http.delete(`/sites/${form.id}`);dialog.value=false;await load()}catch(e){error.value=serverError(e)}
}
function accessOpen(item=null) {
    if(!props.canEdit)return;
    accessError.value='';secretShown.value='';Object.assign(accessForm,accessEmpty());delete accessForm.id;
    if(item) Object.assign(accessForm,item,{secret:''});
    accessDialog.value=true;
}
async function refreshSite() {
    const {data}=await http.get(`/sites/${form.id}`);
    form.accesses=data.data.accesses || [];
    form.has_registry=data.data.has_registry;
}
async function saveAccess() {
    savingAccess.value=true;accessError.value='';
    try {
        const payload={category:accessForm.category,label:accessForm.label,service:accessForm.service||null,url:accessForm.url||null,username:accessForm.username||null,notes:accessForm.notes||null};
        // A string vazia não apaga segredos existentes; para apagar use o botão explícito.
        if(!accessForm.id || accessForm.secret !== '')payload.secret=accessForm.secret||null;
        if(accessForm.id)await http.put(`/sites/${form.id}/accesses/${accessForm.id}`,payload);
        else await http.post(`/sites/${form.id}/accesses`,payload);
        accessDialog.value=false;await refreshSite();await load();
    } catch(e){accessError.value=serverError(e)}finally{savingAccess.value=false;}
}
async function deleteAccess() {
    if(!confirm(`Excluir o acesso ${accessForm.label}?`))return;
    accessError.value='';
    try {await http.delete(`/sites/${form.id}/accesses/${accessForm.id}`);accessDialog.value=false;await refreshSite();await load();}catch(e){accessError.value=serverError(e)}
}
async function revealAccess(item) {
    if(!props.canEdit)return;
    try {const {data}=await http.post(`/sites/${form.id}/accesses/${item.id}/reveal`);secretVisible.value={...secretVisible.value,[item.id]:data.data.secret??''};}
    catch(e){error.value=serverError(e)}
}
function hideAccess(id){const v={...secretVisible.value};delete v[id];secretVisible.value=v;}
async function copySecret(value) {
    try {await navigator.clipboard.writeText(value || '');}catch(e){error.value='Não foi possível copiar. Seu navegador pode exigir HTTPS.';}
}
async function revealRegistry() {
    try {const {data}=await http.post(`/sites/${form.id}/registry/reveal`);registryValue.value=data.data.value||'';registryVisible.value=true;}
    catch(e){error.value=serverError(e)}
}
function newRegistry() {registryVisible.value=true;registryValue.value='';form.registro_br='';}
function editRegistry() {form.registro_br=registryValue.value;}
</script>
<template>
<div class="ace-page">
    <div class="ace-page-head"><div><div class="ace-eyebrow"><span class="ace-eyebrow-dot"></span> PATRIMÔNIO DIGITAL</div><h1>Sites<span>.</span></h1><p>Domínios, hospedagens, infraestrutura e credenciais organizadas por cliente.</p></div><button v-if="canEdit" class="ace-btn ace-btn-primary" @click="open()"><Plus :size="17"/> Novo site</button></div>
    <section class="ace-panel ace-table-panel"><div class="ace-table-toolbar"><div class="ace-search"><Search :size="17"/><input v-model="search" placeholder="Buscar domínio ou nome do site..." aria-label="Buscar sites"/></div><select v-model="filter" class="ace-filter"><option value="">Todos os status</option><option value="active">Ativos</option><option value="inactive">Inativos</option><option value="maintenance">Manutenção</option></select></div>
    <div v-if="listError" class="ace-error">{{listError}}</div><div class="ace-table-wrap"><table class="ace-table"><thead><tr><th>SITE / DOMÍNIO</th><th>CLIENTE</th><th>HOSPEDAGEM</th><th>STATUS</th><th></th></tr></thead><tbody><tr v-for="item in list" :key="item.id" @click="open(item)"><td><div class="ace-name-cell"><span class="ace-initial-icon"><Globe2 :size="17"/></span><div><strong>{{item.name}}</strong><small>{{item.domain}}</small></div></div></td><td>{{item.client?.name || '—'}}</td><td>{{item.hosting_provider || 'Não informado'}}</td><td><span class="ace-status" :class="item.status">{{siteStatus[item.status]}}</span></td><td><button class="ace-icon-button" aria-label="Abrir site"><Pencil :size="16"/></button></td></tr><tr v-if="!loading&&!list.length"><td colspan="5"><div class="ace-empty-state"><Globe2 :size="26"/><strong>Nenhum site encontrado</strong><span>Cadastre um cliente e adicione seus sites.</span></div></td></tr></tbody></table></div><div class="ace-pagination"><span>Página {{page}} de {{pages}}</span><div><button :disabled="page===1" @click="page--"><ChevronLeft :size="18"/></button><button :disabled="page>=pages" @click="page++"><ChevronRight :size="18"/></button></div></div></section>
    <v-dialog v-model="dialog" max-width="1050" scrollable><div class="ace-dialog"><div class="ace-dialog-head"><div><div class="ace-eyebrow">FICHA DIGITAL DO SITE</div><h2>{{form.id?form.name:'Cadastrar site'}}</h2><p>{{form.domain || 'Preencha os dados do site do cliente.'}}</p></div><button class="ace-icon-button" aria-label="Fechar" @click="dialog=false"><X :size="21"/></button></div>
        <div class="ace-dialog-tabs"><button v-for="section in sections" :key="section.id" :class="{active:tab===section.id}" @click="tab=section.id">{{section.name}}</button></div>
        <div class="ace-dialog-body ace-site-dialog-body"><div v-if="error" class="ace-error">{{error}}</div>
            <div v-if="tab==='main'" class="ace-form-section"><div class="ace-form-title">Identificação do site</div><div class="ace-form-grid"><v-select v-model="form.client_id" label="Cliente responsável" :items="clients.map(c=>({title:c.name,value:c.id}))" :readonly="!canEdit"/><v-select v-model="form.status" label="Situação" :items="[{title:'Ativo',value:'active'},{title:'Inativo',value:'inactive'},{title:'Manutenção',value:'maintenance'}]" :readonly="!canEdit"/><v-text-field v-for="f in fieldsMain" :key="f.key" v-model="form[f.key]" :label="f.label" :type="f.type||'text'" :readonly="!canEdit"/></div><v-textarea v-model="form.notes" label="Informações adicionais do site" rows="3" :readonly="!canEdit"/></div>
            <div v-if="tab==='infra'" class="ace-form-section"><div class="ace-form-title">Hospedagem, DNS e controle</div><div class="ace-form-grid"><v-text-field v-for="f in fieldsInfra" :key="f.key" v-model="form[f.key]" :label="f.label" :readonly="!canEdit"/></div><div class="ace-form-title ace-divider-top">Dados do Registro.br <span class="ace-secret-label"><LockKeyhole :size="13"/> Criptografado</span></div><p class="ace-form-help">O conteúdo armazenado fica protegido e só é exibido ao solicitar a revelação.</p>
                <div v-if="form.id && form.has_registry && !registryVisible" class="ace-secret-placeholder"><span>Dados salvos e protegidos</span><button v-if="canEdit" class="ace-btn ace-btn-soft" @click="revealRegistry"><Eye :size="15"/> Revelar dados</button></div>
                <template v-else><v-textarea v-if="registryVisible" v-model="registryValue" label="Dados do Registro.br" rows="4" :readonly="!canEdit" @update:model-value="editRegistry"/><div v-else class="ace-secret-placeholder"><span>Sem dados cadastrados</span><button v-if="canEdit" class="ace-btn ace-btn-soft" @click="newRegistry"><Plus :size="15"/> Adicionar dados</button></div></template>
                <button v-if="registryVisible && form.has_registry" class="ace-link" @click="registryVisible=false;registryValue='';form.registro_br=undefined"><EyeOff :size="14"/> Ocultar</button>
                <p class="ace-form-help">Usuário e senha FTP devem ser cadastrados em “Acessos & senhas”, categoria FTP.</p></div>
            <div v-if="tab==='store'" class="ace-form-section">
                <div class="ace-form-title">Credenciais rápidas — loja e redes sociais <span class="ace-secret-label"><LockKeyhole :size="13"/> Criptografadas</span></div>
                <p class="ace-form-help">Usuários e senhas ficam aqui, por serviço, com revelação controlada e registro de auditoria. Os links são opcionais.</p>
                <div v-if="!form.id" class="ace-info-note"><CircleAlert :size="16"/> Primeiro salve o site para cadastrar credenciais.</div>
                <div class="ace-quick-credentials">
                    <article v-for="service in quickServices" :key="service.key" class="ace-credential-card">
                        <div class="ace-credential-heading"><strong>{{ service.title }}</strong><button v-if="canAddAccess" class="ace-btn ace-btn-soft ace-btn-sm" @click="quickAdd(service)"><Plus :size="14"/> Adicionar</button></div>
                        <div v-if="!quickItems(service).length" class="ace-credential-empty">Nenhuma credencial cadastrada.</div>
                        <div v-for="item in quickItems(service)" :key="item.id" class="ace-credential-entry">
                            <div class="ace-credential-identity"><span>{{ item.label }}</span><strong>{{ item.username || 'Usuário não informado' }}</strong><div class="ace-credential-controls"><button v-if="item.username" class="ace-link" @click="copySecret(item.username)"><Copy :size="14"/> Copiar usuário</button><a v-if="item.url" :href="item.url" target="_blank" rel="noopener noreferrer" class="ace-link"><ExternalLink :size="14"/> Abrir</a></div></div>
                            <div class="ace-credential-secret"><span>Senha</span><strong v-if="secretVisible[item.id]!==undefined" class="ace-credential-secret-text">{{secretVisible[item.id] || 'Não cadastrada'}}</strong><strong v-else>••••••••</strong><div class="ace-credential-controls"><button v-if="canEdit && secretVisible[item.id]===undefined" class="ace-link" @click="revealAccess(item)"><Eye :size="14"/> Revelar</button><button v-if="secretVisible[item.id]!==undefined" class="ace-link" @click="copySecret(secretVisible[item.id])"><Copy :size="14"/> Copiar senha</button><button v-if="secretVisible[item.id]!==undefined" class="ace-link" @click="hideAccess(item.id)"><EyeOff :size="14"/> Ocultar</button><button v-if="canEdit" class="ace-link" @click="quickEdit(item)"><Pencil :size="14"/> Editar</button></div></div>
                        </div>
                    </article>
                </div>
                <div v-if="otherSocialCredentials.length" class="ace-credential-card"><div class="ace-credential-heading"><strong>Outros logins cadastrados</strong></div><div v-for="item in otherSocialCredentials" :key="item.id" class="ace-credential-entry"><div class="ace-credential-identity"><span>{{item.label}}</span><strong>{{item.username || 'Usuário não informado'}}</strong><div class="ace-credential-controls"><button v-if="item.username" class="ace-link" @click="copySecret(item.username)"><Copy :size="14"/> Copiar usuário</button></div></div><div class="ace-credential-secret"><span>Senha</span><strong v-if="secretVisible[item.id]!==undefined">{{secretVisible[item.id] || 'Não cadastrada'}}</strong><strong v-else>••••••••</strong><div class="ace-credential-controls"><button v-if="canEdit && secretVisible[item.id]===undefined" class="ace-link" @click="revealAccess(item)"><Eye :size="14"/> Revelar</button><button v-if="secretVisible[item.id]!==undefined" class="ace-link" @click="copySecret(secretVisible[item.id])"><Copy :size="14"/> Copiar senha</button><button v-if="secretVisible[item.id]!==undefined" class="ace-link" @click="hideAccess(item.id)"><EyeOff :size="14"/> Ocultar</button><button v-if="canEdit" class="ace-link" @click="quickEdit(item)"><Pencil :size="14"/> Editar</button></div></div></div></div>
                <div class="ace-form-title ace-divider-top">Links e referências (opcionais)</div>
                <v-text-field v-model="form.store_url" label="Endereço da loja virtual" :readonly="!canEdit"/>
                <div class="ace-form-grid"><v-text-field v-for="f in socialFields" :key="f.key" v-model="form.social_links[f.key]" :label="f.label" :readonly="!canEdit"/></div>
                <v-textarea v-model="form.social_links.additional" label="Informações adicionais" rows="3" :readonly="!canEdit"/>
            </div>
            <div v-if="tab==='access'" class="ace-form-section"><div class="ace-access-header"><div><div class="ace-form-title">Cofre de acessos</div><p class="ace-form-help">Guarde e-mails, senhas de FTP, painéis, loja, redes sociais e qualquer novo tipo de acesso.</p></div><button v-if="canAddAccess" class="ace-btn ace-btn-primary" @click="accessOpen()"><Plus :size="16"/> Adicionar acesso</button></div>
                <div v-if="!form.id" class="ace-empty-small">Salve primeiro o site para vincular os acessos.</div><div v-else-if="!form.accesses?.length" class="ace-empty-small">Nenhum acesso cadastrado ainda.</div>
                <div v-for="item in (form.accesses || [])" :key="item.id" class="ace-access-item"><div class="ace-access-symbol"><KeyRound :size="19"/></div><div class="ace-access-data"><strong>{{item.label}}</strong><small>{{categoryNames[item.category]}} <span v-if="item.username"> · {{item.username}}</span></small><div v-if="secretVisible[item.id]!==undefined" class="ace-revealed"><code>{{secretVisible[item.id]}}</code><button aria-label="Copiar segredo" @click="copySecret(secretVisible[item.id])"><Copy :size="14"/></button><button aria-label="Ocultar segredo" @click="hideAccess(item.id)"><EyeOff :size="14"/></button></div></div><div class="ace-access-actions"><button v-if="canEdit && secretVisible[item.id]===undefined" class="ace-btn ace-btn-soft ace-btn-sm" @click="revealAccess(item)"><Eye :size="14"/> Revelar</button><button v-if="canEdit" class="ace-icon-button" title="Editar acesso" @click="accessOpen(item)"><Pencil :size="16"/></button></div></div>
            </div>
        </div>
        <div class="ace-dialog-actions"><button v-if="form.id&&canEdit" class="ace-btn ace-btn-danger" @click="remove"><Trash2 :size="16"/> Excluir site</button><span class="ace-flex-spacer"></span><button class="ace-btn ace-btn-outline" @click="dialog=false">Fechar</button><button v-if="canEdit" class="ace-btn ace-btn-primary" :disabled="saving" @click="save"><Save :size="16"/>{{saving?'Salvando...':form.id?'Salvar alterações':'Criar site'}}</button></div>
    </div></v-dialog>
    <v-dialog v-model="accessDialog" max-width="660" scrollable><div class="ace-dialog"><div class="ace-dialog-head"><div><div class="ace-eyebrow">COFRE DE ACESSOS</div><h2>{{accessForm.id?'Editar acesso':'Novo acesso'}}</h2><p>Os segredos são criptografados no servidor.</p></div><button class="ace-icon-button" aria-label="Fechar" @click="accessDialog=false"><X :size="21"/></button></div><div class="ace-dialog-body"><div v-if="accessError" class="ace-error">{{accessError}}</div><div class="ace-form-grid"><v-select v-model="accessForm.category" label="Categoria" :items="catItems"/><v-text-field v-model="accessForm.label" label="Identificação (ex.: E-mail financeiro)"/><v-text-field v-model="accessForm.service" label="Plataforma / serviço"/><v-text-field v-model="accessForm.url" label="Link de acesso (URL)"/><v-text-field v-model="accessForm.username" label="Usuário / e-mail"/><v-text-field v-model="accessForm.secret" label="Senha / token / chave" type="password" autocomplete="new-password" :placeholder="accessForm.id?'Deixe em branco para manter a senha':''"/></div><v-textarea v-model="accessForm.notes" label="Forma de acesso e informações adicionais" rows="3"/><div class="ace-info-note"><CircleAlert :size="16"/> Os segredos nunca são enviados nas listagens. Use “Revelar” quando precisar consultar um acesso existente.</div></div><div class="ace-dialog-actions"><button v-if="accessForm.id" class="ace-btn ace-btn-danger" @click="deleteAccess"><Trash2 :size="16"/> Excluir</button><span class="ace-flex-spacer"></span><button class="ace-btn ace-btn-outline" @click="accessDialog=false">Cancelar</button><button class="ace-btn ace-btn-primary" :disabled="savingAccess" @click="saveAccess"><Save :size="16"/>{{savingAccess?'Salvando...':'Salvar acesso'}}</button></div></div></v-dialog>
</div>
</template>
