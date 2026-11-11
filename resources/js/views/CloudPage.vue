<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import {
  Cloud, Folder, FolderPlus, UploadCloud, Plus, RefreshCw, Grid2X2, List, Search,
  ChevronRight, ArrowLeft, HardDrive, CheckCircle2, CircleAlert, MoreHorizontal,
  FileText, FileImage, FileSpreadsheet, FileArchive, File, Download, Eye,
  Pencil, ArrowRightLeft, Trash2, X, LogOut, ShieldCheck, CloudUpload, Info,
  LayoutGrid, LoaderCircle, LockKeyhole, Check, ExternalLink, GripVertical
} from 'lucide-vue-next';
import { http } from '../lib/http';
import { serverError } from '../lib/ace';

const ROOT = { id: 'root', name: 'Meu Drive' };
const FOLDER_MIME = 'application/vnd.google-apps.folder';
const state = ref(null);
const selectedAccountId = ref(null);
const accounts = computed(() => state.value?.accounts || []);
const selectedAccount = computed(() => accounts.value.find(a => String(a.id) === String(selectedAccountId.value)) || null);
const canManage = computed(() => !!state.value?.can_manage);
const path = ref([ROOT]);
const folderId = computed(() => path.value[path.value.length - 1]?.id || 'root');
const files = ref([]);
const nextPageToken = ref(null);
const search = ref('');
const view = ref('list');
const busy = ref(false);
const loading = ref(false);
const error = ref('');
const notice = ref('');
const selectedItem = ref(null);
const uploadInput = ref(null);
const newFolderVisible = ref(false);
const folderName = ref('');
const moveItem = ref(null);
const destinationPath = ref([ROOT]);
const destinationFolders = ref([]);
const destinationToken = ref(null);
const destinationLoading = ref(false);
const previewItem = ref(null);
const previewUrl = ref('');
const dragging = ref(null);
let listCounter = 0;
let destinationCounter = 0;
let loaded = false;
const redirectParams = new URLSearchParams(window.location.search);
const redirectStatus = redirectParams.get('drive');
const oauthReason = redirectParams.get('reason');
const oauthReasonMessages = { invalid_grant: 'Autorização recusada ou expirada pelo Google. Tente conectar novamente.', invalid_client: 'O Google recusou a configuração OAuth do aplicativo. Contate o suporte.', rate_limited: 'O Google limitou temporariamente as chamadas. Tente novamente mais tarde.', google_unavailable: 'O Google está temporariamente indisponível.', network_error: 'Falha na comunicação com o Google.' };
const oauthMessage = {
  connected: 'Conta Google Drive conectada. Selecione-a na lateral para navegar.',
  cancelled: 'A autorização foi cancelada.',
  token_error: oauthReasonMessages[oauthReason] || 'O Google não concluiu a autorização. Tente novamente; se persistir, informe o suporte DEV.',
  invalid_state: 'A autenticação expirou. Tente novamente.',
  denied: 'Você não tem permissão para conectar esta conta.',
  profile_error: 'Não foi possível confirmar o e-mail da conta Google.',
};
if (redirectStatus) {
  const text = oauthMessage[redirectStatus] || '';
  if (redirectStatus === 'connected') notice.value = text;
  else error.value = text;
}
const isFolder = item => item?.mimeType === FOLDER_MIME;
const isImage = item => item?.mimeType?.startsWith('image/');
const canPreview = item => ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif'].includes(item?.mimeType);
const iconFor = item => isFolder(item) ? Folder : isImage(item) ? FileImage :
  item?.mimeType?.includes('spreadsheet') || /\.xlsx?$/i.test(item?.name || '') ? FileSpreadsheet :
  /\.(zip|7z|rar)$/i.test(item?.name || '') ? FileArchive : FileText;
const sizeOf = v => v === undefined || v === null ? '—' : Number(v) < 1024 ? `${v} B` :
  Number(v) < 1048576 ? `${(Number(v) / 1024).toFixed(1)} KB` : `${(Number(v) / 1048576).toFixed(1)} MB`;
const dateOf = value => value ? new Date(value).toLocaleDateString('pt-BR') : '—';
const visibleFiles = computed(() => {
  const needle = search.value.trim().toLocaleLowerCase('pt-BR');
  return files.value.filter(f => !needle || (f.name || '').toLocaleLowerCase('pt-BR').includes(needle))
    .sort((a,b) => Number(isFolder(b)) - Number(isFolder(a)) ||
      (a.name || '').localeCompare(b.name || '', 'pt-BR', { sensitivity:'base' }));
});
const folderCount = computed(() => files.value.filter(isFolder).length);
const fileCount = computed(() => files.value.length - folderCount.value);
const destinationId = computed(() => destinationPath.value[destinationPath.value.length - 1]?.id || 'root');
function fail(e) { error.value = serverError(e); notice.value = ''; }
function inform(message) { notice.value = message; error.value = ''; }
function apiAccount() { return { account_id: selectedAccountId.value }; }

async function loadAccounts() {
  loading.value = true;
  error.value = '';
  try {
    const { data } = await http.get('/cloud/google/status');
    state.value = data.data;
    const all = data.data.accounts || [];
    if (!all.some(a => String(a.id) === String(selectedAccountId.value))) {
      selectedAccountId.value = all.length ? (redirectStatus === 'connected' ? all[all.length - 1].id : all[0].id) : null;
    }
    if (!all.length) { files.value = []; path.value = [ROOT]; }
  } catch(e) { fail(e); }
  finally { loading.value = false; }
}
async function loadFiles(append = false) {
  if (!selectedAccountId.value || (append && !nextPageToken.value)) return;
  const call = ++listCounter;
  busy.value = true;
  error.value = '';
  try {
    const { data } = await http.get('/cloud/google/manager/items', {
      params: { ...apiAccount(), folder_id: folderId.value, ...(append ? { page_token: nextPageToken.value } : {}) },
      timeout: 35000,
    });
    if (call !== listCounter) return;
    files.value = append ? [...files.value, ...(data.data.files || [])] : (data.data.files || []);
    nextPageToken.value = data.data.nextPageToken || null;
    if (selectedItem.value && !files.value.some(i => i.id === selectedItem.value.id)) selectedItem.value = null;
  } catch(e) { if (call === listCounter) fail(e); }
  finally { if (call === listCounter) busy.value = false; }
}
watch(selectedAccountId, async () => {
  ++listCounter;
  path.value = [ROOT];
  files.value = [];
  nextPageToken.value = null;
  search.value = '';
  selectedItem.value = null;
  closePreview();
  if (loaded && selectedAccountId.value) await loadFiles();
});
onMounted(async () => { await loadAccounts(); loaded = true; if (selectedAccountId.value) await loadFiles(); });
onUnmounted(() => { ++listCounter; ++destinationCounter; closePreview(); });
async function chooseAccount(account) { if (busy.value || loading.value) return; selectedAccountId.value = account.id; }
async function connect() {
  loading.value = true;
  error.value = '';
  try {
    const { data } = await http.get('/cloud/google/authorize');
    window.location.assign(data.data.url);
  } catch(e) { fail(e); loading.value = false; }
}
async function checkSelectedAccount() {
  if (!selectedAccountId.value || !canManage.value || loading.value) return;
  loading.value = true;
  try {
    const { data } = await http.post(`/cloud/google/accounts/${selectedAccountId.value}/check`, {}, { timeout: 30000 });
    await loadAccounts();
    if (data.data.status === 'connected') {
      inform('Conexão OAuth válida: o Google aceitou a renovação da autorização.');
      await loadFiles();
    } else {
      error.value = data.data.message || 'Não foi possível verificar a conexão.';
      notice.value = '';
    }
  } catch(e) { fail(e); }
  finally { loading.value = false; }
}
async function disconnect(account) {
  if (!canManage.value || !window.confirm(`Desconectar ${account.email} do ACE? Nenhum arquivo será apagado do Google Drive.`)) return;
  loading.value = true;
  try {
    await http.delete(`/cloud/google/accounts/${account.id}`);
    inform('Conta desconectada do ACE. Os arquivos permanecem no Google Drive.');
    await loadAccounts();
  } catch(e) { fail(e); }
  finally { loading.value = false; }
}
async function enter(item) {
  if (!isFolder(item)) return;
  path.value = [...path.value, { id: item.id, name: item.name }];
  search.value = '';
  selectedItem.value = null;
  files.value = []; nextPageToken.value = null;
  await loadFiles();
}
async function goPath(index) {
  if (index < 0 || index >= path.value.length) return;
  path.value = path.value.slice(0, index + 1);
  selectedItem.value = null;
  search.value = '';
  files.value = []; nextPageToken.value = null;
  await loadFiles();
}
async function createFolder() {
  if (!folderName.value.trim() || !canManage.value || !selectedAccount.value) return;
  loading.value = true;
  try {
    await http.post('/cloud/google/manager/folders', { ...apiAccount(), name:folderName.value.trim(), parent_id:folderId.value });
    folderName.value = ''; newFolderVisible.value = false;
    inform('Pasta criada com sucesso.');
    await loadFiles();
  } catch(e) { fail(e); }
  finally { loading.value = false; }
}
async function upload(e) {
  const selected = [...(e.target?.files || [])];
  if (e.target) e.target.value = '';
  if (!selected.length || !canManage.value || !selectedAccount.value) return;
  if (selected.some(f => f.size > 10485760)) { error.value = 'O limite atual é 10 MB por arquivo.'; return; }
  loading.value = true;
  try {
    for (const file of selected) {
      const form = new FormData();
      form.append('file', file); form.append('parent_id', folderId.value);
      form.append('account_id', String(selectedAccountId.value));
      await http.post('/cloud/google/manager/uploads', form, { timeout:65000 });
    }
    inform(`${selected.length} arquivo(s) enviado(s).`);
    await loadFiles();
  } catch(e) { fail(e); }
  finally { loading.value = false; }
}
async function download(item) {
  error.value = '';
  try {
    const response = await http.get(`/cloud/google/manager/items/${encodeURIComponent(item.id)}/download`, {
      params: apiAccount(), responseType:'blob', timeout:60000,
    });
    const url = URL.createObjectURL(response.data);
    const anchor = document.createElement('a');
    anchor.href = url; anchor.download = item.name; document.body.appendChild(anchor);
    anchor.click(); anchor.remove();
    setTimeout(() => URL.revokeObjectURL(url), 15000);
  } catch(e) { fail(e); }
}
function closePreview() {
  previewItem.value = null;
  if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
  previewUrl.value = '';
}
async function preview(item) {
  if (!canPreview(item)) { selectedItem.value = item; return; }
  closePreview(); loading.value = true;
  try {
    const response = await http.get(`/cloud/google/manager/items/${encodeURIComponent(item.id)}/preview`, {
      params: apiAccount(), responseType:'blob', timeout:60000,
    });
    previewUrl.value = URL.createObjectURL(response.data);
    previewItem.value = item;
  } catch(e) { fail(e); }
  finally { loading.value = false; }
}
async function rename(item) {
  if (!canManage.value) return;
  const name = window.prompt('Novo nome:', item.name);
  if (!name || !name.trim() || name.trim() === item.name) return;
  loading.value = true;
  try {
    await http.patch(`/cloud/google/manager/items/${encodeURIComponent(item.id)}/rename`, { ...apiAccount(), name:name.trim() });
    path.value = path.value.map(p => p.id === item.id ? { ...p, name:name.trim() } : p);
    inform('Item renomeado.'); await loadFiles();
  } catch(e) { fail(e); }
  finally { loading.value = false; }
}
async function trash(item) {
  if (!canManage.value || !window.confirm(`Mover "${item.name}" para a lixeira?`)) return;
  loading.value = true;
  try {
    await http.delete(`/cloud/google/manager/items/${encodeURIComponent(item.id)}`, { data:apiAccount() });
    inform('Item enviado para a lixeira.'); await loadFiles();
  } catch(e) { fail(e); }
  finally { loading.value = false; }
}
async function loadDestinations(append = false) {
  if (!moveItem.value || (append && !destinationToken.value)) return;
  const call = ++destinationCounter;
  destinationLoading.value = true;
  try {
    const { data } = await http.get('/cloud/google/manager/items', {
      params: { ...apiAccount(), folder_id: destinationId.value, ...(append ? { page_token:destinationToken.value } : {}) }, timeout:35000,
    });
    if (call !== destinationCounter) return;
    const folders = (data.data.files || []).filter(f => isFolder(f) && f.id !== moveItem.value?.id);
    destinationFolders.value = append ? [...destinationFolders.value, ...folders] : folders;
    destinationToken.value = data.data.nextPageToken || null;
  } catch(e) { if (call === destinationCounter) fail(e); }
  finally { if (call === destinationCounter) destinationLoading.value = false; }
}
async function openMove(item) {
  if (!canManage.value) return;
  moveItem.value = item;
  destinationPath.value = [ROOT]; destinationFolders.value = []; destinationToken.value = null;
  await loadDestinations();
}
async function goDestination(index) {
  destinationPath.value = destinationPath.value.slice(0, index+1);
  destinationFolders.value = []; destinationToken.value = null;
  await loadDestinations();
}
async function enterDestination(item) {
  destinationPath.value = [...destinationPath.value, { id:item.id, name:item.name }];
  destinationFolders.value = []; destinationToken.value = null;
  await loadDestinations();
}
async function performMove(item, destId) {
  if (!canManage.value || item.id === destId) return;
  loading.value = true;
  try {
    await http.patch(`/cloud/google/manager/items/${encodeURIComponent(item.id)}/move`, {
      ...apiAccount(), destination_id:destId,
    });
    moveItem.value = null;
    dragging.value = null;
    inform('Item movido com sucesso.');
    await loadFiles();
  } catch(e) { fail(e); }
  finally { loading.value = false; }
}
function startDrag(item, event) {
  if (!canManage.value) return;
  dragging.value = item;
  event.dataTransfer.effectAllowed = 'move';
  event.dataTransfer.setData('text/plain', item.id);
}
async function dropOn(target) {
  const item = dragging.value;
  dragging.value = null;
  if (!item || !isFolder(target) || item.id === target.id) return;
  if (!window.confirm(`Mover "${item.name}" para a pasta "${target.name}"?`)) return;
  await performMove(item, target.id);
}
</script>

<template>
  <div class="ace-page ace-cloud-hub">
    <header class="ace-storage-heading">
      <div>
        <div class="ace-eyebrow"><span class="ace-eyebrow-dot"></span> ACE CLOUD • ARMAZENAMENTO</div>
        <h1>Seus arquivos<span>.</span></h1>
        <p>Organize os arquivos de todas as suas contas, sem sair do ACE.</p>
      </div>
      <div class="ace-storage-heading-actions">
        <span class="ace-storage-security"><ShieldCheck :size="16"/> OAuth seguro</span>
        <button class="ace-btn ace-btn-primary" :disabled="loading || !canManage || !state?.configured || !state?.cloud_enabled" @click="connect">
          <Plus :size="17"/> Conectar conta
        </button>
      </div>
    </header>

    <div v-if="error" role="alert" class="ace-storage-alert is-error"><CircleAlert :size="18"/> {{ error }} <button @click="error=''" aria-label="Fechar"><X :size="17"/></button></div>
    <div v-if="notice" class="ace-storage-alert is-success"><CheckCircle2 :size="18"/> {{ notice }} <button @click="notice=''" aria-label="Fechar"><X :size="17"/></button></div>

    <div v-if="state && !state.cloud_enabled" class="ace-storage-alert is-error">A central de arquivos exige o plano Cloud ou Pro.</div>
    <div v-if="state && !state.configured" class="ace-storage-alert is-error">Configure o Client ID e o Client Secret do Google Drive no servidor.</div>

    <div class="ace-storage-layout">
      <aside class="ace-storage-sidebar">
        <div class="ace-storage-side-title">CONTAS CONECTADAS <span>{{ accounts.length }}</span></div>
        <div class="ace-storage-provider-group"><div class="ace-storage-provider-logo google-logo">G</div><strong>Google Drive</strong><span class="ace-storage-count">{{ accounts.length }}</span></div>
        <button v-for="account in accounts" :key="account.id" class="ace-storage-account" :class="{'is-active':String(selectedAccountId)===String(account.id)}" @click="chooseAccount(account)">
          <span class="ace-storage-account-avatar">{{ (account.email || 'G').slice(0,1).toUpperCase() }}</span>
          <span class="ace-storage-account-copy"><strong>{{ account.email || 'Conta Google' }}</strong><small>{{ account.health_status === 'reconnect_required' ? 'Reconexão necessária' : account.health_status === 'error' ? 'Falha de conexão' : account.health_status === 'connected' ? 'Conectado · verificado' : 'Ainda não verificado' }}</small></span>
          <Check v-if="String(selectedAccountId)===String(account.id)" :size="15"/>
        </button>
        <button v-if="canManage && state?.cloud_enabled" class="ace-storage-add" :disabled="loading || !state?.configured" @click="connect"><Plus :size="16"/> Adicionar outra conta</button>
        <div v-if="!accounts.length" class="ace-storage-noaccounts">Nenhuma conta conectada. Adicione seu Google Drive para começar.</div>
        <div class="ace-storage-side-title is-next">OUTROS PROVEDORES</div>
        <div class="ace-storage-provider-future"><span class="ace-storage-future-mark">i</span><span>iCloud Drive<small>Em breve</small></span></div>
        <div class="ace-storage-provider-future"><span class="ace-storage-future-mark mega">M</span><span>MEGA<small>Em breve</small></span></div>
        <div class="ace-storage-provider-future"><Cloud :size="19"/><span>Outras nuvens<small>Estrutura preparada</small></span></div>
        <div class="ace-storage-sidebar-bottom"><LockKeyhole :size="15"/><span>Login realizado no Google. O ACE não recebe sua senha.</span></div>
      </aside>

      <section class="ace-storage-main">
        <div v-if="selectedAccount" class="ace-storage-account-top">
          <div class="ace-storage-account-info"><div class="ace-storage-provider-logo google-logo">G</div><div><strong>{{ selectedAccount.email }}</strong><span>Google Drive · {{ folderId==='root' ? 'Raiz' : 'Pasta' }}</span></div></div>
          <div class="ace-storage-top-actions">
            <button v-if="canManage" class="ace-storage-quiet" :disabled="busy || loading" title="Testar autorização OAuth sem ler arquivos" @click="checkSelectedAccount"><ShieldCheck :size="17"/><span>Verificar conexão</span></button>
            <button class="ace-storage-quiet" :disabled="busy || loading" title="Atualizar pasta" @click="loadFiles()"><RefreshCw :size="17" :class="{'ace-storage-spin':busy}"/> <span>Atualizar</span></button>
            <button v-if="canManage" class="ace-storage-quiet is-danger" :disabled="loading" title="Desconectar esta conta do ACE" @click="disconnect(selectedAccount)"><LogOut :size="17"/><span>Desconectar</span></button>
          </div>
        </div>

        <template v-if="selectedAccount">
          <div v-if="['error','reconnect_required'].includes(selectedAccount.health_status)" class="ace-storage-alert is-error" role="alert"><CircleAlert :size="18"/> {{ selectedAccount.health_message }} <button v-if="canManage" class="ace-btn ace-btn-outline" @click="connect">Reconectar conta</button></div>
          <div v-else-if="selectedAccount.health_status === 'unknown'" class="ace-storage-alert"><Info :size="18"/> Esta conexão ainda não foi verificada. Use “Verificar conexão” para saber se o Google aceita a autorização.</div>
          <div class="ace-storage-toolbar">
            <div class="ace-storage-search"><Search :size="18"/><input v-model="search" aria-label="Buscar nesta pasta" placeholder="Buscar nesta pasta..."/><button v-if="search" aria-label="Limpar busca" @click="search=''"><X :size="15"/></button></div>
            <button class="ace-btn ace-btn-outline" :disabled="busy || loading || !canManage" @click="newFolderVisible=true"><FolderPlus :size="17"/> Nova pasta</button>
            <button class="ace-btn ace-btn-primary" :disabled="busy || loading || !canManage" @click="uploadInput?.click()"><UploadCloud :size="17"/> Enviar arquivos</button>
            <input ref="uploadInput" type="file" multiple hidden @change="upload"/>
          </div>
          <div class="ace-storage-navigation">
            <div class="ace-storage-breadcrumb">
              <button v-if="path.length>1" class="ace-storage-back" title="Voltar" @click="goPath(path.length-2)"><ArrowLeft :size="18"/></button>
              <template v-for="(part,i) in path" :key="part.id">
                <ChevronRight v-if="i" :size="15" class="ace-storage-crumb-separator"/>
                <button :class="{'is-current':i===path.length-1}" :disabled="i===path.length-1" @click="goPath(i)">{{ part.name }}</button>
              </template>
            </div>
            <div class="ace-storage-view-switch"><button :class="{'is-active':view==='list'}" title="Visualização em lista" @click="view='list'"><List :size="18"/></button><button :class="{'is-active':view==='grid'}" title="Visualização em grade" @click="view='grid'"><Grid2X2 :size="18"/></button></div>
          </div>
          <div class="ace-storage-results-title"><span>Arquivos e pastas <small>{{ visibleFiles.length }} item(ns) exibido(s)</small></span><span v-if="busy" class="ace-storage-loading"><LoaderCircle :size="16" class="ace-storage-spin"/> Carregando</span></div>

          <div v-if="!busy && !visibleFiles.length" class="ace-storage-empty">
            <div class="ace-storage-empty-icon"><Folder :size="30"/></div>
            <strong>{{ search ? 'Nada encontrado nesta pasta' : 'Esta pasta está vazia' }}</strong>
            <p>{{ search ? 'Tente buscar por outro nome.' : 'Crie uma pasta ou envie um arquivo para começar.' }}</p>
          </div>
          <div v-else-if="view==='list'" class="ace-storage-list">
            <div class="ace-storage-list-header"><span>NOME</span><span>MODIFICADO</span><span>TAMANHO</span><span>AÇÕES</span></div>
            <div v-for="item in visibleFiles" :key="item.id" class="ace-storage-item" :class="{'is-selected':selectedItem?.id===item.id}" :draggable="canManage && !busy" @dragstart="startDrag(item,$event)" @dragend="dragging=null" @dragover.prevent @drop.prevent="dropOn(item)">
              <button class="ace-storage-file-title" @click="isFolder(item) ? enter(item) : preview(item)" :title="item.name"><span class="ace-storage-file-icon" :class="{'is-folder':isFolder(item)}"><component :is="iconFor(item)" :size="21"/></span><span class="ace-storage-filename"><strong>{{ item.name }}</strong><small>{{ isFolder(item) ? 'Pasta' : (item.mimeType || 'Arquivo') }}</small></span><ChevronRight v-if="isFolder(item)" :size="16" class="ace-storage-open-indicator"/></button>
              <span class="ace-storage-date">{{ dateOf(item.modifiedTime) }}</span><span class="ace-storage-size">{{ isFolder(item) ? '—' : sizeOf(item.size) }}</span>
              <div class="ace-storage-file-actions">
                <button v-if="canPreview(item)" title="Visualizar" @click="preview(item)"><Eye :size="16"/></button>
                <button v-if="!isFolder(item)" title="Baixar" @click="download(item)"><Download :size="16"/></button>
                <button v-if="canManage" title="Mover" @click="openMove(item)"><ArrowRightLeft :size="16"/></button>
                <button v-if="canManage" title="Renomear" @click="rename(item)"><Pencil :size="16"/></button>
                <button v-if="canManage" title="Mover para lixeira" class="is-danger" @click="trash(item)"><Trash2 :size="16"/></button>
                <button title="Detalhes" @click="selectedItem=selectedItem?.id===item.id ? null : item"><Info :size="16"/></button>
              </div>
            </div>
          </div>
          <div v-else class="ace-storage-grid">
            <article v-for="item in visibleFiles" :key="item.id" class="ace-storage-grid-card" :draggable="canManage && !busy" @dragstart="startDrag(item,$event)" @dragend="dragging=null" @dragover.prevent @drop.prevent="dropOn(item)">
              <button class="ace-storage-grid-open" @click="isFolder(item) ? enter(item) : preview(item)"><span class="ace-storage-file-icon" :class="{'is-folder':isFolder(item)}"><component :is="iconFor(item)" :size="31"/></span><strong :title="item.name">{{ item.name }}</strong><small>{{ isFolder(item) ? 'Pasta' : sizeOf(item.size) }}</small></button>
              <div class="ace-storage-grid-actions"><button v-if="!isFolder(item)" title="Baixar" @click="download(item)"><Download :size="16"/></button><button v-if="canManage" title="Mover" @click="openMove(item)"><ArrowRightLeft :size="16"/></button><button v-if="canManage" title="Renomear" @click="rename(item)"><Pencil :size="16"/></button><button v-if="canManage" title="Lixeira" @click="trash(item)"><Trash2 :size="16"/></button><button title="Detalhes" @click="selectedItem=item"><Info :size="16"/></button></div>
            </article>
          </div>
          <button v-if="nextPageToken" class="ace-storage-more" :disabled="busy" @click="loadFiles(true)">{{ busy ? 'Carregando...' : 'Carregar mais arquivos' }}</button>
          <div class="ace-storage-footer"><ShieldCheck :size="15"/> Somente arquivos criados ou autorizados para o ACE são exibidos (escopo drive.file). Mover entre contas diferentes ainda não está disponível.</div>
        </template>
        <div v-else class="ace-storage-welcome"><div class="ace-storage-welcome-icon"><CloudUpload :size="36"/></div><h2>Sua nuvem, dentro do ACE.</h2><p>Conecte uma ou várias contas Google e organize arquivos e pastas em um só lugar.</p><button class="ace-btn ace-btn-primary" :disabled="loading || !canManage || !state?.configured || !state?.cloud_enabled" @click="connect"><Plus :size="18"/> Conectar Google Drive</button><span>iCloud Drive e MEGA serão adicionados futuramente.</span></div>
      </section>

      <aside v-if="selectedItem && selectedAccount" class="ace-storage-inspector">
        <div class="ace-storage-inspector-head"><strong>Detalhes do item</strong><button aria-label="Fechar detalhes" @click="selectedItem=null"><X :size="19"/></button></div>
        <div class="ace-storage-inspector-icon"><component :is="iconFor(selectedItem)" :size="36"/></div>
        <h3>{{ selectedItem.name }}</h3>
        <dl><dt>Tipo</dt><dd>{{ isFolder(selectedItem) ? 'Pasta' : selectedItem.mimeType }}</dd><dt>Tamanho</dt><dd>{{ sizeOf(selectedItem.size) }}</dd><dt>Modificado</dt><dd>{{ dateOf(selectedItem.modifiedTime) }}</dd><dt>Conta</dt><dd>{{ selectedAccount.email }}</dd></dl>
        <button v-if="canManage" class="ace-btn ace-btn-outline" @click="openMove(selectedItem)"><ArrowRightLeft :size="16"/> Mover item</button>
      </aside>
    </div>

    <div v-if="newFolderVisible" class="ace-storage-overlay" @click.self="newFolderVisible=false"><section class="ace-storage-modal" role="dialog" aria-modal="true" aria-label="Criar pasta"><div class="ace-storage-modal-header"><div><span>NOVA PASTA</span><h3>Criar pasta</h3></div><button @click="newFolderVisible=false" aria-label="Fechar"><X :size="21"/></button></div><p>Será criada em {{ path.map(p=>p.name).join(' / ') }} na conta {{ selectedAccount?.email }}.</p><form @submit.prevent="createFolder"><input v-model="folderName" maxlength="180" autofocus placeholder="Nome da nova pasta" required/><div class="ace-storage-modal-actions"><button type="button" class="ace-btn ace-btn-outline" @click="newFolderVisible=false">Cancelar</button><button type="submit" class="ace-btn ace-btn-primary" :disabled="loading || !folderName.trim()"><FolderPlus :size="16"/> Criar pasta</button></div></form></section></div>

    <div v-if="moveItem" class="ace-storage-overlay" @click.self="moveItem=null"><section class="ace-storage-modal ace-storage-move-modal" role="dialog" aria-modal="true" aria-label="Mover arquivo ou pasta"><div class="ace-storage-modal-header"><div><span>ORGANIZAR ARQUIVOS</span><h3>Mover {{ moveItem.name }}</h3></div><button @click="moveItem=null" aria-label="Fechar"><X :size="21"/></button></div><p>Escolha uma pasta de destino nesta mesma conta: <strong>{{ selectedAccount?.email }}</strong></p><div class="ace-storage-destination-path"><template v-for="(part,i) in destinationPath" :key="part.id"><ChevronRight v-if="i" :size="14"/><button @click="goDestination(i)">{{ part.name }}</button></template></div><div class="ace-storage-destination-list"><div v-if="destinationLoading" class="ace-storage-destination-empty">Carregando pastas...</div><div v-else-if="!destinationFolders.length" class="ace-storage-destination-empty">Nenhuma subpasta nesta localização.</div><button v-for="folder in destinationFolders" :key="folder.id" class="ace-storage-destination" @click="enterDestination(folder)"><Folder :size="19"/><span>{{ folder.name }}</span><ChevronRight :size="16"/></button></div><button v-if="destinationToken" class="ace-storage-more" :disabled="destinationLoading" @click="loadDestinations(true)">Carregar mais pastas</button><div class="ace-storage-modal-actions"><button class="ace-btn ace-btn-outline" @click="moveItem=null">Cancelar</button><button class="ace-btn ace-btn-primary" :disabled="loading || destinationLoading || destinationId===moveItem.id" @click="performMove(moveItem,destinationId)"><ArrowRightLeft :size="16"/> Mover para esta pasta</button></div></section></div>

    <div v-if="previewItem" class="ace-storage-overlay" @click.self="closePreview"><section class="ace-storage-modal ace-storage-preview" role="dialog" aria-modal="true" :aria-label="previewItem.name"><div class="ace-storage-modal-header"><div><span>VISUALIZAÇÃO NO ACE</span><h3>{{ previewItem.name }}</h3></div><button aria-label="Fechar" @click="closePreview"><X :size="21"/></button></div><img v-if="isImage(previewItem)" :src="previewUrl" :alt="previewItem.name"/><iframe v-else :src="previewUrl" :title="previewItem.name"/><div class="ace-storage-modal-actions"><button class="ace-btn ace-btn-outline" @click="closePreview">Fechar</button><button class="ace-btn ace-btn-primary" @click="download(previewItem)"><Download :size="16"/> Baixar</button></div></section></div>
  </div>
</template>

<style src="../../css/ace-cloud-hub.css"></style>
