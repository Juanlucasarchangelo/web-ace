<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { ArrowUpRight, CalendarDays, CheckCircle2, Eye, EyeOff, ImagePlus, Megaphone, Pencil, Plus, Save, Settings2, Trash2, X } from 'lucide-vue-next';
import { http } from '../lib/http';
import { serverError } from '../lib/ace';
import AcePopupCard from './AcePopupCard.vue';

const list = ref([]);
const loading = ref(false);
const saving = ref(false);
const error = ref('');
const notice = ref('');
const editing = ref(null);
const editorOpen = ref(false);
const upload = ref(null);
const removeImage = ref(false);
const imagePreview = ref('');
const form = ref(blank());
const types = [
  { id:'text', title:'Texto + imagem opcional', desc:'Mensagem com título, descrição e chamada.' },
  { id:'image', title:'Imagem clicável', desc:'Arte completa com link configurável.' },
  { id:'offer', title:'Destaque promocional', desc:'Campanha com selo, foto e chamada.' },
  { id:'notice', title:'Aviso discreto', desc:'Faixa informativa sem bloquear a tela.' },
];
const destinations = [ { id:'home', name:'Home pública' }, { id:'login', name:'Login' }, { id:'signup', name:'Cadastro / contratação' }, { id:'dashboard', name:'Dashboard do cliente' } ];
const triggerLabels = { immediate:'Ao entrar na página', delay:'Após um tempo', scroll:'Ao rolar a página', exit:'Ao tentar sair' };
const frequencyLabels = { always:'Em toda visita', session:'Uma vez por sessão', day:'Uma vez por dia', once:'Uma única vez por navegador' };
const typeLabels = { text:'Texto', image:'Imagem', offer:'Destaque', notice:'Aviso' };
const activeCount = computed(() => list.value.filter(x => x.is_active).length);
const nowLabel = date => date ? new Date(date).toLocaleString('pt-BR') : 'Sem limite';
const previewItem = computed(() => ({ ...form.value, image_url: imagePreview.value || null }));

function friendlyError(e) { return e?.response?.status === 429 ? 'Muitas operações em pouco tempo. Aguarde um minuto e tente novamente.' : serverError(e); }
function normalizeUrl(value) { const v=(value||'').trim(); if(!v) return ''; if(/^https?:\/\//i.test(v) || v.startsWith('/')) return v; return 'https://' + v; }
function blank() { return {
  type:'text', title:'', description:'', badge_text:'', cta_label:'Saiba mais', cta_url:'',
  placements:['home'], trigger:'delay', delay_seconds:3, scroll_percent:40,
  frequency:'session', priority:0, is_active:false, starts_at:'', ends_at:'',
}; }
function toLocalDate(value) {
  if (!value) return '';
  const d = new Date(value);
  if (Number.isNaN(d.getTime())) return '';
  const p = n => String(n).padStart(2,'0');
  return `${d.getFullYear()}-${p(d.getMonth()+1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`;
}
function clearImage() { if (imagePreview.value.startsWith('blob:')) URL.revokeObjectURL(imagePreview.value); imagePreview.value=''; }
onUnmounted(clearImage);
function chooseImage(event) {
  const file = event.target.files?.[0];
  if (!file) return;
  if (!['image/png','image/jpeg','image/webp'].includes(file.type) || file.size>4*1024*1024) {
    error.value='Imagem inválida. Use JPG, PNG ou WebP de até 4 MB.';event.target.value='';return;
  }
  clearImage();upload.value=file;removeImage.value=false;imagePreview.value=URL.createObjectURL(file);error.value='';
}
function newItem() { editing.value=null;form.value=blank();upload.value=null;removeImage.value=false;clearImage();error.value='';notice.value='';editorOpen.value=true; }
function editItem(item) {
  editing.value=item.id;
  form.value={
    type:item.type,title:item.title||'',description:item.description||'',badge_text:item.badge_text||'',
    cta_label:item.cta_label||'',cta_url:item.cta_url||'',placements:[...(item.placements||['home'])],
    trigger:item.trigger,delay_seconds:item.delay_seconds,scroll_percent:item.scroll_percent,
    frequency:item.frequency,priority:item.priority,is_active:!!item.is_active,
    starts_at:toLocalDate(item.starts_at),ends_at:toLocalDate(item.ends_at),
  };
  upload.value=null;removeImage.value=false;clearImage();imagePreview.value=item.admin_image_url||'';
  error.value='';notice.value='';editorOpen.value=true;
}
function resetEditor() { editorOpen.value=false;editing.value=null;upload.value=null;removeImage.value=false;clearImage();error.value=''; }
function closeEditor() { if (!saving.value) resetEditor(); }
function unlinkImage() { clearImage();upload.value=null;removeImage.value=true; }
function togglePlacement(placement, checked) {
  if (checked && !form.value.placements.includes(placement)) form.value.placements.push(placement);
  if (!checked) form.value.placements=form.value.placements.filter(x=>x!==placement);
}
async function load() {
  loading.value=true;error.value='';
  try { list.value=(await http.get('/dev/popups')).data.data || []; }
  catch(e) { error.value=friendlyError(e); }
  finally { loading.value=false; }
}
async function save() {
  if (saving.value) return;
  if (!form.value.placements.length) {error.value='Escolha ao menos uma página para o popup.';return;}
  if (form.value.type==='image' && !upload.value && !imagePreview.value) {error.value='Envie uma imagem para este formato.';return;}
  if (form.value.starts_at && form.value.ends_at && form.value.ends_at <= form.value.starts_at) {error.value='O término deve ser depois do início.';return;}
  saving.value=true;error.value='';notice.value='';
  try {
    const body=new FormData();
    for (const key of ['type','title','description','badge_text','cta_label','cta_url','trigger','delay_seconds','scroll_percent','frequency','priority']) {
      body.append(key,String(key==='cta_url' ? normalizeUrl(form.value[key]) : (form.value[key] ?? '')));
    }
    form.value.placements.forEach((value,i) => body.append(`placements[${i}]`,value));
    body.append('is_active',form.value.is_active?'1':'0');
    body.append('starts_at',form.value.starts_at || '');
    body.append('ends_at',form.value.ends_at || '');
    if (upload.value) body.append('image',upload.value);
    if (removeImage.value) body.append('remove_image','1');
    await http.post(editing.value ? `/dev/popups/${editing.value}` : '/dev/popups',body,{timeout:30000});
    resetEditor();await load();notice.value='Popup salvo. As condições de publicação serão respeitadas automaticamente.';
  } catch(e) { error.value=friendlyError(e); }
  finally { saving.value=false; }
}
async function changeVisibility(item) {
  if (saving.value) return;
  saving.value=true;error.value='';notice.value='';
  try {
    const body=new FormData();
    for (const key of ['type','title','description','badge_text','cta_label','cta_url','trigger','delay_seconds','scroll_percent','frequency','priority']) body.append(key,String(key==='cta_url' ? normalizeUrl(item[key]) : (item[key] ?? '')));
    (item.placements||[]).forEach((value,i)=>body.append(`placements[${i}]`,value));
    body.append('is_active',item.is_active?'0':'1');
    body.append('starts_at',toLocalDate(item.starts_at));
    body.append('ends_at',toLocalDate(item.ends_at));
    await http.post(`/dev/popups/${item.id}`,body,{timeout:30000});
    await load();notice.value='Visibilidade do popup atualizada.';
  } catch(e) { error.value=friendlyError(e); }
  finally { saving.value=false; }
}
async function remove(item) {
  if (saving.value || !window.confirm(`Remover o popup "${item.title || 'Imagem'}"? O registro e a imagem permanecem preservados no histórico.`)) return;
  saving.value=true;error.value='';notice.value='';
  try { await http.delete(`/dev/popups/${item.id}`);await load();notice.value='Popup removido sem apagar o histórico.'; }
  catch(e) {error.value=friendlyError(e);}
  finally {saving.value=false;}
}
function status(item) {
  if (!item.is_active) return 'Pausado';
  if (item.starts_at && new Date(item.starts_at)>new Date()) return 'Agendado';
  if (item.ends_at && new Date(item.ends_at)<=new Date()) return 'Encerrado';
  return 'Publicado';
}
onMounted(load);
</script>
<template>
  <section class="ace-popup-admin">
    <div class="ace-popup-admin-header">
      <div><span class="ace-popup-overline">ADMINISTRAÇÃO / ANÚNCIOS / POPUPS</span><h2>Campanhas no momento certo.</h2><p>Crie comunicações visuais, escolha onde e quando aparecem e evite exibir a mesma mensagem em excesso. As regras valem para todos os visitantes elegíveis.</p></div>
      <button type="button" class="ace-sponsor-primary" @click="newItem"><Plus :size="17"/> Criar popup</button>
    </div>
    <div class="ace-popup-admin-stats"><span><strong>{{activeCount}}</strong> ativo(s)</span><span>{{list.length}} cadastrado(s)</span><span>4 formatos disponíveis</span><span>Publicação global gerenciada pelo DEV</span></div>
    <p v-if="error && !editorOpen" class="ace-popup-admin-alert error" role="alert">{{error}}</p>
    <p v-if="notice && !editorOpen" class="ace-popup-admin-alert success" role="status">{{notice}}</p>
    <div v-if="loading" class="ace-popup-admin-empty">Carregando campanhas...</div>
    <div v-else-if="!list.length" class="ace-popup-admin-empty"><Megaphone :size="36"/><strong>Pronto para sua primeira campanha</strong><p>Popups são publicados somente depois que você ativá-los no DEV.</p><button type="button" class="ace-sponsor-primary" @click="newItem"><Plus :size="16"/> Criar primeiro popup</button></div>
    <div v-else class="ace-popup-admin-list">
      <article v-for="item in list" :key="item.id" class="ace-popup-admin-row">
        <div class="ace-popup-admin-thumb"><img v-if="item.admin_image_url" :src="item.admin_image_url" alt="" loading="lazy"/><Megaphone v-else :size="25"/></div>
        <div class="ace-popup-admin-summary"><strong>{{item.title || 'Anúncio em imagem'}}</strong><p>{{typeLabels[item.type]}} · {{(item.placements||[]).map(p=>destinations.find(d=>d.id===p)?.name||p).join(', ')}}</p><small>{{triggerLabels[item.trigger]}} · {{frequencyLabels[item.frequency]}} · Prioridade {{item.priority}}</small><small v-if="item.starts_at || item.ends_at">{{nowLabel(item.starts_at)}} até {{nowLabel(item.ends_at)}}</small></div>
        <div class="ace-popup-admin-meta"><span class="ace-popup-pill" :class="{active:status(item)==='Publicado'}">{{status(item)}}</span><small>#{{item.id}}</small></div>
        <div class="ace-popup-row-actions"><button type="button" :title="item.is_active?'Pausar':'Ativar'" :disabled="saving" @click="changeVisibility(item)"><Eye v-if="item.is_active" :size="17"/><EyeOff v-else :size="17"/></button><button type="button" title="Editar" :disabled="saving" @click="editItem(item)"><Pencil :size="17"/></button><button type="button" title="Remover" class="danger" :disabled="saving" @click="remove(item)"><Trash2 :size="17"/></button></div>
      </article>
    </div>
    <div v-if="editorOpen" class="ace-popup-editor-screen" @click.self="closeEditor">
      <form class="ace-popup-editor" @submit.prevent="save" aria-label="Editor de popups">
        <div class="ace-popup-editor-header"><div><span class="ace-popup-overline">CAMPANHAS / CONFIGURAÇÃO</span><h3>{{editing?'Editar popup':'Nova campanha'}}</h3></div><button type="button" aria-label="Fechar" @click="closeEditor"><X :size="19"/></button></div>
        <p v-if="error" class="ace-popup-admin-alert error" role="alert" style="margin-top:16px">{{error}}</p>
        <div class="ace-popup-form-grid">
          <div class="ace-popup-editor-panel">
            <h4><ImagePlus :size="17"/> 1. Conteúdo e formato</h4>
            <label>Tipo do popup<select v-model="form.type"><option v-for="type in types" :key="type.id" :value="type.id">{{type.title}}</option></select></label>
            <p class="ace-popup-help">{{types.find(x=>x.id===form.type)?.desc}}</p>
            <label v-if="form.type!=='image'">Título *<input v-model="form.title" type="text" maxlength="160" required placeholder="Sua mensagem principal"/></label>
            <label v-else>Título alternativo (acessibilidade)<input v-model="form.title" type="text" maxlength="160" placeholder="Descreva a imagem"/></label>
            <label v-if="form.type!=='image'">Descrição {{form.type==='text'?'*':'(opcional)'}}<textarea v-model="form.description" maxlength="2000" :required="form.type==='text'" rows="3" placeholder="Escreva sua comunicação..."></textarea></label>
            <label v-if="form.type==='offer'">Selo do destaque<input v-model="form.badge_text" type="text" maxlength="70" placeholder="Ex.: CONDIÇÃO ESPECIAL"/></label>
            <label v-if="form.type!=='notice'">Imagem {{form.type==='image'?'*':'(opcional)'}}
              <div class="ace-popup-upload"><img v-if="imagePreview" :src="imagePreview" alt="Pré-visualização da imagem"/><button v-if="imagePreview && form.type!=='image'" type="button" class="ace-popup-btn-outline" style="padding:6px 10px;font-size:11px;margin-bottom:10px" @click="unlinkImage">Remover imagem desta campanha</button><input type="file" accept="image/png,image/jpeg,image/webp" :required="form.type==='image'&&!imagePreview" @change="chooseImage"/><p class="ace-popup-help">PNG, JPG ou WebP · Até 4 MB. Para imagem completa, prefira arte horizontal responsiva.</p></div>
            </label>
            <div class="ace-popup-input-row"><label>Texto do botão<input v-model="form.cta_label" type="text" maxlength="70" placeholder="Saiba mais"/></label><label>Link da chamada<input v-model="form.cta_url" type="text" maxlength="500" placeholder="exemplo.com.br ou /contratar"/></label></div>
          </div>
          <div class="ace-popup-editor-panel">
            <h4><Settings2 :size="17"/> 2. Regras de exibição</h4>
            <label>Páginas onde aparece</label>
            <div class="ace-popup-choices"><label v-for="dest in destinations" :key="dest.id" class="ace-popup-choice"><input type="checkbox" :checked="form.placements.includes(dest.id)" @change="togglePlacement(dest.id,$event.target.checked)"/> {{dest.name}}</label></div>
            <label>Quando disparar<select v-model="form.trigger"><option v-for="(label,id) in triggerLabels" :key="id" :value="id">{{label}}</option></select></label>
            <div v-if="form.trigger==='delay'" class="ace-popup-input-row"><label>Esperar (segundos)<input v-model.number="form.delay_seconds" type="number" min="0" max="180" required/></label></div>
            <div v-if="form.trigger==='scroll'" class="ace-popup-input-row"><label>Percentual de rolagem<input v-model.number="form.scroll_percent" type="number" min="10" max="90" required/></label></div>
            <p v-if="form.trigger==='exit'" class="ace-popup-help">No desktop aparece ao tentar sair pela parte superior. Em dispositivos de toque, surge após alguns segundos.</p>
            <label>Frequência por visitante<select v-model="form.frequency"><option v-for="(label,id) in frequencyLabels" :key="id" :value="id">{{label}}</option></select></label>
            <h4><CalendarDays :size="17"/> 3. Período de publicação</h4>
            <div class="ace-popup-input-row"><label>Início (opcional)<input v-model="form.starts_at" type="datetime-local"/></label><label>Término (opcional)<input v-model="form.ends_at" type="datetime-local"/></label></div>
            <p class="ace-popup-help">Fuso horário: America/São_Paulo (configuração padrão do ACE). Sem datas, fica disponível enquanto estiver ativo.</p>
            <div class="ace-popup-input-row"><label>Prioridade<input v-model.number="form.priority" type="number" min="0" max="9999" required/></label><label class="ace-popup-choice" style="align-self:end;padding-bottom:12px"><input v-model="form.is_active" type="checkbox"/> Publicar quando elegível</label></div>
            <p class="ace-popup-help">Se várias campanhas estiverem disponíveis na mesma página, somente a de maior prioridade ainda não vista será mostrada.</p>
          </div>
        </div>
        <div class="ace-popup-editor-preview"><p class="ace-popup-editor-preview-label">PRÉ-VISUALIZAÇÃO DO CONTEÚDO (NÃO PUBLICADA)</p><AcePopupCard :item="previewItem" preview @close="()=>{}"/></div>
        <div class="ace-popup-editor-footer"><span class="ace-popup-help"><CheckCircle2 :size="14" style="vertical-align:middle"/> Edição exclusiva do DEV</span><div><button type="button" class="ace-popup-btn-outline" :disabled="saving" @click="closeEditor">Cancelar</button><button type="submit" class="ace-popup-btn-primary" :disabled="saving"><Save :size="15" style="vertical-align:middle"/> {{saving?'Salvando...':'Salvar popup'}}</button></div></div>
      </form>
    </div>
  </section>
</template>
