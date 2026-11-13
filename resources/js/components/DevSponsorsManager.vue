<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { ArrowUpRight, CheckCircle2, Eye, EyeOff, ImagePlus, Pencil, Plus, Save, Trash2, X } from 'lucide-vue-next';
import { http } from '../lib/http';
import MonoSponsorLogo from './MonoSponsorLogo.vue';
import { serverError } from '../lib/ace';

const sponsors = ref([]);
const busy = ref(false);
const loading = ref(false);
const error = ref('');
const notice = ref('');
const editorOpen = ref(false);
const editingId = ref(null);
const logoFile = ref(null);
const preview = ref('');
const form = ref(blank());
function friendlyError(e) { return e?.response?.status === 429 ? 'Muitas operações em pouco tempo. Aguarde um minuto e tente novamente.' : serverError(e); }
function normalizeUrl(value) { const v=(value||'').trim(); if(!v) return ''; if(/^https?:\/\//i.test(v) || v.startsWith('/')) return v; return 'https://' + v; }
function blank() { return { name:'', description:'', website_url:'', sort_order:0, is_active:true }; }
const activeCount = computed(() => sponsors.value.filter(x=>x.is_active).length);

function clearPreview() { if (preview.value?.startsWith('blob:')) URL.revokeObjectURL(preview.value); preview.value=''; }
onUnmounted(clearPreview);
function chooseLogo(event) {
  clearPreview();
  const f = event.target.files?.[0];
  logoFile.value = f || null;
  if (!f) return;
  if (!['image/png', 'image/jpeg', 'image/webp'].includes(f.type) || f.size > 2 * 1024 * 1024) {
    error.value='Envie PNG, JPG ou WebP de até 2 MB.'; logoFile.value=null; event.target.value=''; return;
  }
  preview.value=URL.createObjectURL(f);
}
function newSponsor() { editingId.value=null; form.value=blank(); logoFile.value=null; clearPreview(); error.value=''; editorOpen.value=true; }
function editSponsor(item) {
  editingId.value=item.id;
  form.value={name:item.name, description:item.description||'', website_url:item.website_url||'', sort_order:item.sort_order, is_active:item.is_active};
  logoFile.value=null; clearPreview(); preview.value=item.admin_logo_url; error.value=''; editorOpen.value=true;
}
function closeEditor() { editorOpen.value=false; editingId.value=null; logoFile.value=null; clearPreview(); }
async function load() {
  loading.value=true; error.value='';
  try { sponsors.value=(await http.get('/dev/sponsors')).data.data || []; }
  catch(e) { error.value=friendlyError(e); }
  finally { loading.value=false; }
}
async function save() {
  if(busy.value) return;
  if(!form.value.name.trim() || (!editingId.value && !logoFile.value)) {error.value='Informe o nome e envie uma logo.';return;}
  busy.value=true; error.value=''; notice.value='';
  try {
    const payload=new FormData();
    payload.append('name',form.value.name.trim());
    payload.append('description',form.value.description.trim());
    payload.append('website_url',normalizeUrl(form.value.website_url));
    payload.append('sort_order',String(Math.max(0,Number(form.value.sort_order)||0)));
    payload.append('is_active',form.value.is_active?'1':'0');
    if(logoFile.value) payload.append('logo',logoFile.value);
    // Multipart POST works with Laravel without relying on PHP multipart PUT parsing.
    const endpoint=editingId.value ? `/dev/sponsors/${editingId.value}` : '/dev/sponsors';
    await http.post(endpoint,payload, {timeout:30000});
    closeEditor(); await load(); notice.value='Patrocinador salvo. A home pública mostra somente os ativos.';
  } catch(e){error.value=friendlyError(e);}
  finally{busy.value=false;}
}
async function setActive(item) {
  if(busy.value) return;
  busy.value=true;error.value='';notice.value='';
  try {
    const payload=new FormData();payload.append('is_active',item.is_active?'0':'1');
    await http.post(`/dev/sponsors/${item.id}`,payload);
    await load(); notice.value='Visibilidade atualizada.';
  }catch(e){error.value=friendlyError(e);}finally{busy.value=false;}
}
async function remove(item) {
  if(busy.value || !window.confirm(`Remover "${item.name}" da vitrine? A remoção preserva o registro para recuperação.`))return;
  busy.value=true;error.value='';notice.value='';
  try {await http.delete(`/dev/sponsors/${item.id}`); await load(); notice.value='Patrocinador removido da vitrine.';}
  catch(e){error.value=friendlyError(e);}finally{busy.value=false;}
}
onMounted(load);
</script>
<template>
  <section class="ace-sponsor-admin">
    <div class="ace-sponsor-admin-head">
      <p class="ace-module-hint">Marcas publicadas na página inicial. Os patrocinadores inativos permanecem no cadastro.</p>
      <button type="button" class="ace-sponsor-primary" @click="newSponsor"><Plus :size="17"/> Adicionar patrocinador</button>
    </div>
    <div class="ace-sponsor-stats"><span><CheckCircle2 :size="17"/> {{activeCount}} ativo(s)</span><span>{{sponsors.length}} cadastrado(s)</span><a href="/" target="_blank" rel="noopener noreferrer">Ver home pública <ArrowUpRight :size="15"/></a></div>
    <p v-if="error" class="ace-sponsor-alert error" role="alert">{{error}}</p><p v-if="notice" class="ace-sponsor-alert success" role="status">{{notice}}</p>
    <div v-if="loading" class="ace-sponsor-empty">Carregando patrocinadores...</div>
    <div v-else-if="!sponsors.length" class="ace-sponsor-empty"><ImagePlus :size="36"/><strong>Nenhum patrocinador cadastrado</strong><span>Adicione o primeiro parceiro para exibir sua marca na home do ACE.</span><button type="button" class="ace-sponsor-primary" @click="newSponsor"><Plus :size="16"/> Cadastrar primeiro</button></div>
    <div v-else class="ace-sponsor-list">
      <article v-for="item in sponsors" :key="item.id" class="ace-sponsor-item">
        <div class="ace-sponsor-image"><MonoSponsorLogo :src="item.admin_logo_url" :alt="`Logo de ${item.name}`" /></div>
        <div class="ace-sponsor-info"><strong>{{item.name}}</strong><small>{{item.description||'Sem descrição'}}</small><a v-if="item.website_url" :href="item.website_url" target="_blank" rel="noopener noreferrer nofollow sponsored">{{item.website_url}} <ArrowUpRight :size="13"/></a></div>
        <div class="ace-sponsor-state"><span :class="item.is_active?'is-active':'is-hidden'">{{item.is_active?'Publicado':'Oculto'}}</span><small>Ordem {{item.sort_order}}</small></div>
        <div class="ace-sponsor-actions"><button type="button" :disabled="busy" :title="item.is_active?'Ocultar':'Publicar'" @click="setActive(item)"><Eye v-if="item.is_active" :size="17"/><EyeOff v-else :size="17"/></button><button type="button" :disabled="busy" title="Editar" @click="editSponsor(item)"><Pencil :size="17"/></button><button type="button" class="danger" :disabled="busy" title="Remover" @click="remove(item)"><Trash2 :size="17"/></button></div>
      </article>
    </div>
    <div v-if="editorOpen" class="ace-sponsor-modal-backdrop" @click.self="closeEditor">
      <form class="ace-sponsor-modal" @submit.prevent="save" aria-label="Cadastro de patrocinador">
        <div class="ace-sponsor-modal-head"><div><span class="ace-sponsor-overline">GESTÃO DE MARCAS</span><h3>{{editingId?'Editar patrocinador':'Novo patrocinador'}}</h3></div><button type="button" title="Fechar" @click="closeEditor"><X :size="20"/></button></div>
        <div class="ace-sponsor-editor-grid">
          <label class="ace-sponsor-upload"><strong>Logotipo <span v-if="!editingId">*</span></strong><div class="ace-sponsor-preview"><MonoSponsorLogo v-if="preview" :src="preview" alt="Prévia monocromática do logotipo"/><ImagePlus v-else :size="30"/></div><span>PNG, JPG ou WebP · Até 2 MB. O ACE exibirá a marca em preto e ajustará o espaço visual automaticamente.</span><input type="file" accept="image/png,image/jpeg,image/webp" :required="!editingId" @change="chooseLogo"/></label>
          <div class="ace-sponsor-fields">
            <label>Nome do patrocinador *<input v-model="form.name" type="text" required maxlength="140" placeholder="Ex.: Empresa Parceira"/></label>
            <label>Site do patrocinador <input v-model="form.website_url" type="text" maxlength="500" placeholder="exemplo.com.br"/></label>
            <label>Descrição (opcional)<input v-model="form.description" type="text" maxlength="240" placeholder="Ex.: Parceiro de tecnologia"/></label>
            <div class="ace-sponsor-two-col"><label>Ordem de exibição<input v-model.number="form.sort_order" type="number" min="0" max="99999"/></label><label class="ace-sponsor-toggle"><input v-model="form.is_active" type="checkbox"/> Publicar na home</label></div>
          </div>
        </div>
        <div class="ace-sponsor-modal-actions"><button type="button" class="ace-sponsor-ghost" @click="closeEditor">Cancelar</button><button type="submit" class="ace-sponsor-primary" :disabled="busy"><Save :size="16"/> {{busy?'Salvando...':'Salvar patrocinador'}}</button></div>
      </form>
    </div>
  </section>
</template>
