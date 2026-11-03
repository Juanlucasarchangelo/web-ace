<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import {
  Cloud, FolderPlus, UploadCloud, Folder, FileText, FileImage, FileSpreadsheet,
  FileArchive, Download, RefreshCw, ShieldCheck, LogOut, ExternalLink,
  ChevronRight, Home, ArrowLeft, ArrowRightLeft, Pencil, Trash2, Eye,
  Search, X, HardDrive, Check, LoaderCircle
} from 'lucide-vue-next';
import { http } from '../lib/http';
import { serverError } from '../lib/ace';

const ROOT = { id: 'root', name: 'Meu Drive' };
const FOLDER_MIME = 'application/vnd.google-apps.folder';
const state = ref(null);
const files = ref([]);
const path = ref([ROOT]);
const pageToken = ref(null);
const busy = ref(false);
const listBusy = ref(false);
const error = ref('');
const feedback = ref('');
const query = ref('');
const folderName = ref('');
const uploadInput = ref(null);
const moveItem = ref(null);
const destinationPath = ref([ROOT]);
const destinationFolders = ref([]);
const destinationPageToken = ref(null);
const destinationBusy = ref(false);
const previewItem = ref(null);
const previewUrl = ref('');
const draggedItem = ref(null);
let activeRequest = 0;
const redirectNotice = new URLSearchParams(window.location.search).get('drive');
const notices = {
  connected: 'Google Drive conectado com sucesso.',
  cancelled: 'Autorização cancelada.',
  token_error: 'Não foi possível conectar ao Google. Consulte o diagnóstico do servidor.',
  invalid_state: 'Autorização expirada ou inválida. Tente novamente.',
  denied: 'Sem permissão para conectar esta conta.',
};
if (redirectNotice) feedback.value = notices[redirectNotice] || '';

const folderId = computed(() => path.value[path.value.length - 1].id);
const destinationId = computed(() => destinationPath.value[destinationPath.value.length - 1].id);
const canManage = computed(() => !!state.value?.can_manage);
const visibleFiles = computed(() => {
  const text = query.value.trim().toLocaleLowerCase('pt-BR');
  return files.value.filter(item => !text || (item.name || '').toLocaleLowerCase('pt-BR').includes(text))
    .sort((a, b) => Number(isFolder(b)) - Number(isFolder(a)) ||
      (a.name || '').localeCompare(b.name || '', 'pt-BR', { sensitivity: 'base' }));
});
const isFolder = item => item?.mimeType === FOLDER_MIME;
const isGoogleNative = item => !!item?.mimeType?.startsWith('application/vnd.google-apps.');
const canPreview = item => ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif'].includes(item?.mimeType);
const isImage = item => item?.mimeType?.startsWith('image/');
const iconFor = item => isFolder(item) ? Folder : isImage(item) ? FileImage :
  (item?.mimeType?.includes('spreadsheet') || /\.xlsx?$/i.test(item?.name || '')) ? FileSpreadsheet :
  /\.(zip|rar|7z)$/i.test(item?.name || '') ? FileArchive : FileText;
const formatSize = value => value == null ? '—' : Number(value) < 1024 ? `${value} B` :
  Number(value) < 1024 * 1024 ? `${(Number(value) / 1024).toFixed(1)} KB` :
  `${(Number(value) / (1024 * 1024)).toFixed(1)} MB`;
const formatDate = value => value ? new Date(value).toLocaleDateString('pt-BR') : '—';
function notify(message) { feedback.value = message; error.value = ''; }
function fail(exception) { error.value = serverError(exception); feedback.value = ''; }

async function loadItems(append = false) {
  if (append ? !pageToken.value : listBusy.value) return;
  const requestId = ++activeRequest;
  listBusy.value = true;
  error.value = '';
  try {
    const { data } = await http.get('/cloud/google/manager/items', {
      params: { folder_id: folderId.value, ...(append ? { page_token: pageToken.value } : {}) },
      timeout: 35000,
    });
    if (requestId !== activeRequest) return;
    files.value = append ? [...files.value, ...(data.data.files || [])] : (data.data.files || []);
    pageToken.value = data.data.nextPageToken || null;
  } catch (exception) {
    if (requestId === activeRequest) fail(exception);
  } finally {
    if (requestId === activeRequest) listBusy.value = false;
  }
}
async function load() {
  busy.value = true;
  error.value = '';
  try {
    const { data } = await http.get('/cloud/google/status');
    state.value = data.data;
    if (state.value.connected) await loadItems();
    else { files.value = []; pageToken.value = null; path.value = [ROOT]; }
  } catch (exception) { fail(exception); }
  finally { busy.value = false; }
}
onMounted(load);

async function enterFolder(item) {
  if (!isFolder(item)) return;
  path.value = [...path.value, { id: item.id, name: item.name }];
  query.value = '';
  files.value = []; pageToken.value = null;
  await loadItems();
}
async function goTo(index) {
  if (index < 0 || index >= path.value.length) return;
  path.value = path.value.slice(0, index + 1);
  query.value = '';
  files.value = []; pageToken.value = null;
  await loadItems();
}
async function connect() {
  busy.value = true; error.value = '';
  try {
    const { data } = await http.get('/cloud/google/authorize');
    window.location.assign(data.data.url);
  } catch (exception) { fail(exception); busy.value = false; }
}
async function createFolder() {
  const name = folderName.value.trim();
  if (!name || !canManage.value) return;
  busy.value = true; error.value = '';
  try {
    await http.post('/cloud/google/manager/folders', { name, parent_id: folderId.value });
    folderName.value = '';
    notify('Pasta criada nesta localização.');
    await loadItems();
  } catch (exception) { fail(exception); }
  finally { busy.value = false; }
}
async function upload(event) {
  const selected = Array.from(event.target.files || []);
  event.target.value = '';
  if (!selected.length || !canManage.value) return;
  if (selected.some(file => file.size > 10 * 1024 * 1024)) {
    error.value = 'O limite atual é 10 MB por arquivo.';
    return;
  }
  busy.value = true; error.value = '';
  try {
    for (const file of selected) {
      const form = new FormData();
      form.append('file', file);
      form.append('parent_id', folderId.value);
      await http.post('/cloud/google/manager/uploads', form, { timeout: 65000 });
    }
    notify(`${selected.length} arquivo(s) enviado(s) para esta pasta.`);
    await loadItems();
  } catch (exception) { fail(exception); }
  finally { busy.value = false; }
}
async function download(item) {
  error.value = '';
  try {
    const response = await http.get(`/cloud/google/files/${encodeURIComponent(item.id)}/download`, {
      responseType: 'blob', timeout: 60000,
    });
    const url = URL.createObjectURL(response.data);
    const anchor = document.createElement('a');
    anchor.href = url; anchor.download = item.name; document.body.appendChild(anchor);
    anchor.click(); anchor.remove();
    setTimeout(() => URL.revokeObjectURL(url), 15000);
  } catch (exception) { fail(exception); }
}
function closePreview() {
  previewItem.value = null;
  if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
  previewUrl.value = '';
}
onUnmounted(closePreview);
async function preview(item) {
  if (!canPreview(item)) return;
  closePreview(); error.value = ''; busy.value = true;
  try {
    const { data } = await http.get(`/cloud/google/manager/items/${encodeURIComponent(item.id)}/preview`, {
      responseType: 'blob', timeout: 60000,
    });
    previewUrl.value = URL.createObjectURL(data);
    previewItem.value = item;
  } catch (exception) { fail(exception); }
  finally { busy.value = false; }
}
async function rename(item) {
  if (!canManage.value) return;
  const name = window.prompt('Novo nome para o item:', item.name);
  if (name === null || !name.trim() || name.trim() === item.name) return;
  busy.value = true; error.value = '';
  try {
    await http.patch(`/cloud/google/manager/items/${encodeURIComponent(item.id)}/rename`, { name: name.trim() });
    // Update the navigation breadcrumb when renaming its currently selected folder.
    path.value = path.value.map(part => part.id === item.id ? { ...part, name: name.trim() } : part);
    notify('Item renomeado.');
    await loadItems();
  } catch (exception) { fail(exception); }
  finally { busy.value = false; }
}
async function trash(item) {
  if (!canManage.value || !window.confirm(`Mover "${item.name}" para a lixeira do Google Drive?`)) return;
  busy.value = true; error.value = '';
  try {
    await http.delete(`/cloud/google/manager/items/${encodeURIComponent(item.id)}`);
    notify('Item enviado para a lixeira.');
    await loadItems();
  } catch (exception) { fail(exception); }
  finally { busy.value = false; }
}
async function loadDestination(append = false) {
  if (!moveItem.value || (append && !destinationPageToken.value)) return;
  destinationBusy.value = true; error.value = '';
  try {
    const { data } = await http.get('/cloud/google/manager/items', {
      params: {
        folder_id: destinationId.value,
        ...(append ? { page_token: destinationPageToken.value } : {}),
      }, timeout: 35000,
    });
    const folders = (data.data.files || []).filter(isFolder).filter(f => f.id !== moveItem.value?.id);
    destinationFolders.value = append ? [...destinationFolders.value, ...folders] : folders;
    destinationPageToken.value = data.data.nextPageToken || null;
  } catch (exception) { fail(exception); }
  finally { destinationBusy.value = false; }
}
async function openMove(item) {
  if (!canManage.value) return;
  moveItem.value = item;
  destinationPath.value = [ROOT];
  destinationFolders.value = []; destinationPageToken.value = null;
  await loadDestination();
}
async function enterDestination(item) {
  destinationPath.value = [...destinationPath.value, { id: item.id, name: item.name }];
  destinationFolders.value = []; destinationPageToken.value = null;
  await loadDestination();
}
async function goDestination(index) {
  destinationPath.value = destinationPath.value.slice(0, index + 1);
  destinationFolders.value = []; destinationPageToken.value = null;
  await loadDestination();
}
async function performMove(item, target) {
  if (!canManage.value || !item || target === item.id) {
    error.value = 'Selecione uma pasta de destino diferente do próprio item.';
    return;
  }
  busy.value = true; error.value = '';
  try {
    await http.patch(`/cloud/google/manager/items/${encodeURIComponent(item.id)}/move`, {
      destination_id: target,
    });
    notify(`"${item.name}" movido com sucesso.`);
    moveItem.value = null;
    await loadItems();
  } catch (exception) { fail(exception); }
  finally { busy.value = false; }
}
function dropStart(item, event) {
  if (!canManage.value) return;
  draggedItem.value = item;
  event.dataTransfer.effectAllowed = 'move';
  event.dataTransfer.setData('text/plain', item.id);
}
function dropEnd() { draggedItem.value = null; }
async function dropInto(target) {
  const item = draggedItem.value;
  draggedItem.value = null;
  if (!item || !canManage.value || item.id === target.id) return;
  if (window.confirm(`Mover "${item.name}" para "${target.name}"?`)) {
    await performMove(item, target.id);
  }
}
async function disconnect() {
  if (!canManage.value || !window.confirm('Desconectar a conta Google deste workspace?')) return;
  busy.value = true; error.value = '';
  try {
    await http.delete('/cloud/google/connection');
    notify('Conta desconectada.');
    path.value = [ROOT];
    await load();
  } catch (exception) { fail(exception); }
  finally { busy.value = false; }
}
</script>

<template>
  <div class="ace-page ace-drive">
    <div class="ace-page-head">
      <div>
        <div class="ace-eyebrow"><span class="ace-eyebrow-dot"></span> ACE CLOUD</div>
        <h1>Gerenciador de arquivos<span>.</span></h1>
        <p>Organize e mova seus arquivos sem sair do ACE.</p>
      </div>
      <span class="ace-quiet-mark"><ShieldCheck :size="17"/> OAuth 2.0</span>
    </div>

    <div v-if="error" class="ace-error" role="alert">{{ error }}</div>
    <div v-if="feedback" class="ace-info-note" role="status">{{ feedback }}</div>

    <section v-if="state && !state.cloud_enabled" class="ace-panel ace-cloud-intro">
      <Cloud :size="28"/><h2>Disponível nos planos Cloud e Pro</h2>
      <p>Ative um dos planos para usar o armazenamento na nuvem.</p>
      <a href="/#planos" class="ace-btn ace-btn-primary">Conhecer planos</a>
    </section>

    <section v-else class="ace-panel ace-cloud-intro ace-drive-panel">
      <div class="ace-cloud-provider">
        <Cloud :size="26"/>
        <div><h2>Google Drive</h2><p v-if="state?.connected">Conectado: <strong>{{ state.email || 'Conta autorizada' }}</strong></p><p v-else>Conecte sua conta para gerenciar seus arquivos no ACE.</p></div>
        <span class="ace-status" :class="state?.connected ? 'active' : 'inactive'">{{ state?.connected ? 'Conectado' : 'Não conectado' }}</span>
      </div>
      <div v-if="!state?.configured" class="ace-info-note">O administrador precisa configurar o OAuth do Google Drive no servidor.</div>
      <div v-if="state && !state.connected && canManage">
        <button class="ace-btn ace-btn-primary" :disabled="busy || !state.configured" @click="connect">Conectar Google Drive <ExternalLink :size="16"/></button>
      </div>

      <template v-if="state?.connected">
        <div class="ace-drive-tools">
          <div class="ace-drive-search"><Search :size="17"/><input v-model="query" type="search" placeholder="Buscar nesta pasta..." aria-label="Buscar nesta pasta"/></div>
          <button class="ace-btn ace-btn-outline" :disabled="busy || listBusy" @click="loadItems()"><RefreshCw :size="16"/> Atualizar</button>
          <template v-if="canManage">
            <button class="ace-btn ace-btn-outline" :disabled="busy" @click="uploadInput?.click()"><UploadCloud :size="17"/> Enviar arquivos</button>
            <input ref="uploadInput" type="file" multiple class="ace-hidden-file" @change="upload"/>
          </template>
        </div>
        <div v-if="canManage" class="ace-drive-create">
          <input v-model="folderName" class="ace-cloud-input" placeholder="Nome da nova pasta nesta localização" maxlength="180" @keyup.enter="createFolder"/>
          <button class="ace-btn ace-btn-primary" :disabled="busy || !folderName.trim()" @click="createFolder"><FolderPlus :size="17"/> Criar pasta</button>
        </div>

        <nav class="ace-drive-breadcrumb" aria-label="Localização atual">
          <button v-for="(part, index) in path" :key="part.id" type="button" :disabled="listBusy" @click="goTo(index)" @dragover.prevent @drop.prevent="dropInto(part)">
            <Home v-if="index === 0" :size="16"/><Folder v-else :size="15"/>
            <span>{{ part.name }}</span><ChevronRight v-if="index < path.length - 1" :size="15"/>
          </button>
        </nav>

        <div class="ace-drive-files">
          <div class="ace-drive-list-heading"><span>Nome</span><span>Modificado</span><span>Tamanho</span><span>Ações</span></div>
          <div v-if="listBusy && !files.length" class="ace-drive-empty"><LoaderCircle :size="21" class="ace-drive-spin"/> Carregando arquivos...</div>
          <div v-else-if="!visibleFiles.length" class="ace-drive-empty"><HardDrive :size="29"/><strong>{{ query ? 'Nenhum item encontrado' : 'Esta pasta está vazia' }}</strong><small>{{ query ? 'Experimente outro termo ou carregue mais itens.' : 'Crie uma pasta ou envie arquivos aqui.' }}</small></div>
          <div v-for="item in visibleFiles" :key="item.id" class="ace-drive-file" :draggable="canManage" @dragstart="dropStart(item, $event)" @dragend="dropEnd" @dragover="isFolder(item) ? $event.preventDefault() : null" @drop.prevent="isFolder(item) ? dropInto(item) : null">
            <button class="ace-drive-item-name" type="button" :title="item.name" @click="isFolder(item) ? enterFolder(item) : canPreview(item) ? preview(item) : null">
              <span class="ace-drive-icon" :class="{ 'ace-drive-folder-icon': isFolder(item) }"><component :is="iconFor(item)" :size="21"/></span>
              <span><strong>{{ item.name }}</strong><small>{{ isFolder(item) ? 'Pasta · clique para abrir' : (item.mimeType || 'Arquivo') }}</small></span>
              <ChevronRight v-if="isFolder(item)" :size="17" class="ace-drive-open"/>
            </button>
            <span class="ace-drive-date">{{ formatDate(item.modifiedTime) }}</span>
            <span class="ace-drive-size">{{ isFolder(item) ? '—' : formatSize(item.size) }}</span>
            <div class="ace-drive-row-actions">
              <button v-if="canPreview(item)" title="Visualizar no ACE" aria-label="Visualizar" :disabled="busy" @click="preview(item)"><Eye :size="17"/></button>
              <button v-if="!isFolder(item) && !isGoogleNative(item)" title="Baixar arquivo" aria-label="Baixar" :disabled="busy" @click="download(item)"><Download :size="17"/></button>
              <button v-if="canManage" title="Mover para outra pasta" aria-label="Mover" :disabled="busy" @click="openMove(item)"><ArrowRightLeft :size="17"/></button>
              <button v-if="canManage" title="Renomear" aria-label="Renomear" :disabled="busy" @click="rename(item)"><Pencil :size="16"/></button>
              <button v-if="canManage" title="Enviar para a lixeira" aria-label="Enviar para lixeira" :disabled="busy" @click="trash(item)"><Trash2 :size="16"/></button>
            </div>
          </div>
        </div>
        <button v-if="pageToken" class="ace-btn ace-btn-outline" :disabled="busy || listBusy" @click="loadItems(true)">{{ listBusy ? 'Carregando...' : 'Carregar mais itens' }}</button>
        <p class="ace-drive-tip">Dica: use <strong><ArrowRightLeft :size="13"/> Mover</strong> ou arraste um item para uma pasta. Você também pode arrastar para o caminho acima.</p>
        <div class="ace-cloud-disconnect" v-if="canManage"><button class="ace-link" :disabled="busy" @click="disconnect"><LogOut :size="15"/> Desconectar conta</button></div>
      </template>
    </section>
    <p class="ace-drive-scope">O ACE gerencia arquivos criados ou autorizados para este aplicativo, conforme o escopo atual <code>drive.file</code>. Arquivos antigos não autorizados podem não aparecer.</p>

    <!-- Move destination browser: choose any accessible folder inside the ACE. -->
    <div v-if="moveItem" class="ace-drive-overlay" @click.self="moveItem = null">
      <section class="ace-drive-dialog" role="dialog" aria-modal="true" aria-label="Mover arquivo ou pasta">
        <div class="ace-drive-dialog-head"><div><span>MOVER ITEM</span><h3>{{ moveItem.name }}</h3></div><button class="ace-drive-close" title="Fechar" @click="moveItem = null"><X :size="21"/></button></div>
        <p>Escolha uma pasta de destino. A alteração será feita diretamente no Google Drive, sem sair do ACE.</p>
        <nav class="ace-drive-breadcrumb ace-drive-destination-crumb" aria-label="Pasta de destino">
          <button v-for="(part, index) in destinationPath" :key="part.id" :disabled="destinationBusy" @click="goDestination(index)"><Home v-if="index === 0" :size="15"/><Folder v-else :size="15"/> {{ part.name }} <ChevronRight v-if="index < destinationPath.length - 1" :size="14"/></button>
        </nav>
        <div class="ace-drive-destination-list">
          <div v-if="destinationBusy" class="ace-drive-empty">Carregando pastas...</div>
          <div v-else-if="!destinationFolders.length" class="ace-drive-empty">Nenhuma subpasta visível nesta localização.</div>
          <button v-for="folder in destinationFolders" :key="folder.id" class="ace-drive-destination-item" @click="enterDestination(folder)"><Folder :size="19"/> <span>{{ folder.name }}</span> <ChevronRight :size="16"/></button>
        </div>
        <button v-if="destinationPageToken" class="ace-btn ace-btn-outline" :disabled="destinationBusy" @click="loadDestination(true)">Carregar mais pastas</button>
        <div class="ace-drive-dialog-footer">
          <button class="ace-btn ace-btn-outline" @click="moveItem = null">Cancelar</button>
          <button class="ace-btn ace-btn-primary" :disabled="busy || destinationBusy || destinationId === moveItem.id" @click="performMove(moveItem, destinationId)"><Check :size="17"/> Mover para {{ destinationPath[destinationPath.length - 1].name }}</button>
        </div>
      </section>
    </div>

    <div v-if="previewItem" class="ace-drive-overlay" @click.self="closePreview">
      <section class="ace-drive-dialog ace-drive-preview" role="dialog" aria-modal="true" aria-label="Visualização do arquivo">
        <div class="ace-drive-dialog-head"><div><span>VISUALIZAÇÃO NO ACE</span><h3>{{ previewItem.name }}</h3></div><button class="ace-drive-close" @click="closePreview" title="Fechar"><X :size="21"/></button></div>
        <img v-if="isImage(previewItem)" :src="previewUrl" :alt="previewItem.name"/>
        <iframe v-else :src="previewUrl" :title="previewItem.name"/>
        <div class="ace-drive-dialog-footer"><button class="ace-btn ace-btn-outline" @click="closePreview">Fechar</button><button class="ace-btn ace-btn-primary" @click="download(previewItem)"><Download :size="16"/> Baixar</button></div>
      </section>
    </div>
  </div>
</template>

<style src="../../css/drive-manager.css"></style>
