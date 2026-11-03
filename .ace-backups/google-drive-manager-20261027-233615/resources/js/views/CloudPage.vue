<script setup>
import { onMounted, ref } from 'vue';
import { Cloud, FolderPlus, UploadCloud, Folder, FileText, Download, RefreshCw, ShieldCheck, LogOut, ExternalLink } from 'lucide-vue-next';
import { http } from '../lib/http';
import { serverError } from '../lib/ace';
const state = ref(null), files = ref([]), pageToken = ref(null), busy = ref(false), error = ref(''), feedback = ref('');
const uploadInput = ref(null), folderName = ref('');
const redirectNotice = new URLSearchParams(window.location.search).get('drive');
const notices = {connected:'Google Drive conectado com sucesso.',cancelled:'Autorização cancelada.',token_error:'O Google não autorizou a conexão. Verifique as configurações.',invalid_state:'Autorização expirada ou inválida. Tente novamente.',denied:'Sem permissão para conectar esta conta.'};
if (redirectNotice) feedback.value = notices[redirectNotice] || '';
async function load() {
    busy.value = true; error.value = '';
    try {
        const { data } = await http.get('/cloud/google/status');state.value = data.data;
        if (state.value.connected) {const list = await http.get('/cloud/google/files');files.value=list.data.data.files || [];pageToken.value=list.data.data.nextPageToken || null;}
        else {files.value=[];pageToken.value=null;}
    } catch(e) {error.value=serverError(e);} finally {busy.value=false;}
}
onMounted(load);
async function connect() {
    busy.value=true;error.value='';
    try { const {data}=await http.get('/cloud/google/authorize');window.location.assign(data.data.url); }
    catch(e) {error.value=serverError(e);busy.value=false;}
}
async function createFolder() {
    if(!folderName.value.trim())return;
    busy.value=true;error.value='';
    try {await http.post('/cloud/google/folders',{name:folderName.value.trim()});folderName.value='';feedback.value='Pasta criada.';await load();}
    catch(e){error.value=serverError(e);}finally{busy.value=false;}
}
async function upload(event) {
    const file=event.target.files?.[0];if(!file)return;
    if(file.size>10*1024*1024){error.value='O limite é 10 MB por arquivo.';event.target.value='';return;}
    busy.value=true;error.value='';
    try {const data=new FormData();data.append('file',file);await http.post('/cloud/google/files',data,{timeout:65000});feedback.value='Arquivo enviado com sucesso.';await load();}
    catch(e){error.value=serverError(e);}finally{busy.value=false;event.target.value='';}
}
async function download(file) {
    error.value='';
    try {
        const response=await http.get(`/cloud/google/files/${encodeURIComponent(file.id)}/download`,{responseType:'blob',timeout:60000});
        const url=URL.createObjectURL(response.data);const a=document.createElement('a');a.href=url;a.download=file.name;a.click();setTimeout(()=>URL.revokeObjectURL(url),5000);
    } catch(e) {error.value='Falha ao baixar. Arquivos nativos do Google devem ser abertos no Drive.';}
}
async function more() {
    if(!pageToken.value)return;
    busy.value=true;error.value='';
    try {const {data}=await http.get('/cloud/google/files',{params:{page_token:pageToken.value}});files.value.push(...(data.data.files||[]));pageToken.value=data.data.nextPageToken||null;}
    catch(e){error.value=serverError(e);}finally{busy.value=false;}
}
async function disconnect() {
    if(!confirm('Desconectar esta conta Google do workspace?'))return;
    busy.value=true;error.value='';
    try {await http.delete('/cloud/google/connection');feedback.value='Conta desconectada.';await load();}
    catch(e){error.value=serverError(e);}finally{busy.value=false;}
}
</script>
<template>
<div class="ace-page">
    <div class="ace-page-head"><div><div class="ace-eyebrow"><span class="ace-eyebrow-dot"></span> ACE CLOUD</div><h1>Integrações em nuvem<span>.</span></h1><p>Arquivos do workspace com autorização e controle de acesso.</p></div><span class="ace-quiet-mark"><ShieldCheck :size="17"/> OAuth 2.0</span></div>
    <div v-if="error" class="ace-error">{{error}}</div>
    <div v-if="feedback" class="ace-info-note">{{feedback}}</div>
    <section v-if="state && !state.cloud_enabled" class="ace-panel ace-cloud-intro"><Cloud :size="27"/><h2>Disponível nos planos Cloud e Pro</h2><p>Sua empresa usa o plano Free. As integrações com armazenamento são liberadas mediante ativação do plano Cloud ou Pro pelo administrador.</p><a href="/#planos" class="ace-btn ace-btn-primary">Conhecer planos</a></section>
    <section v-else class="ace-panel ace-cloud-intro">
        <div class="ace-cloud-provider"><Cloud :size="25"/><div><h2>Google Drive</h2><p v-if="state?.connected">Conectado: <strong>{{state.email || 'Conta autorizada'}}</strong></p><p v-else>Conecte com sua Conta Google e autorize o ACE. Não informe a senha do Google no ACE.</p></div><span class="ace-status" :class="state?.connected?'active':'inactive'">{{state?.connected?'Conectado':'Não conectado'}}</span></div>
        <div v-if="!state?.configured" class="ace-info-note">O administrador precisa configurar o Client ID, Client Secret e URI de retorno do OAuth no servidor.</div>
        <div v-if="state && !state.connected && state.can_manage"><button class="ace-btn ace-btn-primary" :disabled="busy || !state.configured" @click="connect">Conectar Google Drive <ExternalLink :size="16"/></button></div>
        <template v-else-if="state?.connected">
            <p class="ace-cloud-hint">Permissões atuais: {{state.scope?.includes('/auth/drive.file') ? 'Arquivos criados ou explicitamente autorizados ao ACE' : 'Conforme autorização concedida pelo Google'}}. Nenhuma senha Google é armazenada.</p>
            <div v-if="state.can_manage" class="ace-cloud-actions"><button class="ace-btn ace-btn-outline" :disabled="busy" @click="load"><RefreshCw :size="16"/> Atualizar</button><button class="ace-btn ace-btn-outline" :disabled="busy" @click="uploadInput?.click()"><UploadCloud :size="16"/> Enviar arquivo</button><input ref="uploadInput" type="file" class="ace-hidden-file" @change="upload"/><input v-model="folderName" class="ace-cloud-input" placeholder="Nome da nova pasta" maxlength="180" @keyup.enter="createFolder"/><button class="ace-btn ace-btn-primary" :disabled="busy || !folderName.trim()" @click="createFolder"><FolderPlus :size="16"/> Criar pasta</button></div>
            <div class="ace-cloud-files"><div class="ace-form-title">Arquivos disponíveis no ACE</div><p v-if="!files.length" class="ace-form-help">Nenhum arquivo disponível para este aplicativo. Crie uma pasta ou envie um arquivo para começar.</p><div v-for="file in files" :key="file.id" class="ace-cloud-file"><Folder v-if="file.mimeType==='application/vnd.google-apps.folder'" :size="20"/><FileText v-else :size="20"/><div><strong>{{file.name}}</strong><small>{{file.modifiedTime?new Date(file.modifiedTime).toLocaleDateString('pt-BR'):''}}</small></div><a v-if="file.webViewLink" :href="file.webViewLink" target="_blank" rel="noopener noreferrer" class="ace-btn ace-btn-soft ace-btn-sm"><ExternalLink :size="15"/> Abrir</a><button v-if="file.mimeType!=='application/vnd.google-apps.folder' && !file.mimeType?.startsWith('application/vnd.google-apps.')" class="ace-btn ace-btn-soft ace-btn-sm" @click="download(file)"><Download :size="15"/> Baixar</button></div></div>
            <button v-if="pageToken" class="ace-btn ace-btn-outline" :disabled="busy" @click="more">Carregar mais arquivos</button>
            <div v-if="state.can_manage" class="ace-cloud-disconnect"><button class="ace-link" :disabled="busy" @click="disconnect"><LogOut :size="15"/> Desconectar conta</button></div>
        </template>
    </section>
    <div class="ace-info-note ace-page-note">Para produção pública com acesso amplo aos arquivos existentes, pode ser necessária verificação de escopos restritos pelo Google. Os backups programados e a IA serão implementados em uma próxima etapa.</div>
</div>
</template>
