<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Plus, Pencil, Trash2, Eye, EyeOff, X, ImagePlus, Megaphone } from 'lucide-vue-next';
import { http } from '../lib/http';
import { serverError } from '../lib/ace';

const slots = [
  {id:'after_features', label:'01 · Após os recursos', hint:'Banner inteiro no primeiro intervalo da Home'},
  {id:'after_control', label:'02 · Meio da Home', hint:'Banner inteiro depois da seção de controle'},
  {id:'before_plans_left', label:'03A · Antes dos planos / esquerda', hint:'Coluna independente: imagens, Google ou HTML'},
  {id:'before_plans_right', label:'03B · Antes dos planos / direita', hint:'Coluna independente: imagens, Google ou HTML'},
  {id:'footer', label:'04 · Rodapé', hint:'Banner inteiro antes do rodapé institucional'},
];
const ads=ref([]),loading=ref(false),busy=ref(false),error=ref(''),notice=ref(''),editor=ref(false),editing=ref(null);
const imageFiles=ref([]),imagePreviews=ref([]),removed=ref([]),existing=ref([]);
const blank=()=>({name:'',provider:'direct',placement:'after_features',headline:'',description:'',destination_url:'',google_client:'',google_slot:'',html_code:'',priority:0,is_active:false,starts_at:'',ends_at:''});
const form=ref(blank());
const countActive=computed(()=>ads.value.filter(x=>x.is_active).length);
const friendly=e=>e?.response?.status===429?'Muitas operações em pouco tempo. Aguarde e tente novamente.':e?.response?.data?.message||Object.values(e?.response?.data?.errors||{})?.flat()?.join(' ')||serverError(e);
const formatDate=v=>v?new Date(v).toLocaleString('pt-BR'):'Sem limite';
const localTime=v=>v?new Date(v).toLocaleString('sv-SE').slice(0,16).replace(' ','T'):'';
function clearPreviews(){imagePreviews.value.forEach(url=>URL.revokeObjectURL(url));imagePreviews.value=[];}
onUnmounted(clearPreviews);
function newAd(placement='after_features'){editing.value=null;form.value={...blank(),placement};imageFiles.value=[];existing.value=[];removed.value=[];clearPreviews();error.value='';notice.value='';editor.value=true;}
function editAd(ad){editing.value=ad.id;form.value={...blank(),...ad,starts_at:localTime(ad.starts_at),ends_at:localTime(ad.ends_at)};imageFiles.value=[];existing.value=[...(ad.admin_images||[])];removed.value=[];clearPreviews();error.value='';editor.value=true;}
function close(){editor.value=false;editing.value=null;clearPreviews();imageFiles.value=[];}
function chooseImages(ev){const files=Array.from(ev.target.files||[]);const invalid=files.find(f=>!['image/jpeg','image/png','image/webp'].includes(f.type)||f.size>4*1024*1024);if(invalid){error.value='Cada imagem deve ser PNG, JPG ou WebP de até 4 MB.';ev.target.value='';return;}if(imageFiles.value.length+existing.value.length+files.length>11){error.value='Limite de 11 imagens por anúncio (incluindo imagem original).';ev.target.value='';return;}files.forEach(f=>{imageFiles.value.push(f);imagePreviews.value.push(URL.createObjectURL(f));});ev.target.value='';error.value='';}
function removeNew(idx){URL.revokeObjectURL(imagePreviews.value[idx]);imagePreviews.value.splice(idx,1);imageFiles.value.splice(idx,1);}
function removeExisting(img){existing.value=existing.value.filter(x=>x!==img);if(img.id)removed.value.push(img.id);else form.value.remove_image=true;}
async function load(){loading.value=true;error.value='';try{ads.value=(await http.get('/dev/ads')).data.data||[];}catch(e){error.value=friendly(e);}finally{loading.value=false;}}
function payload(ad=form.value){const data=new FormData();for(const key of ['name','provider','placement','headline','description','destination_url','google_client','google_slot','html_code','priority','is_active','starts_at','ends_at']){let val=ad[key]??'';if(key==='is_active')val=val?'1':'0';data.append(key,String(val));}
 if(ad===form.value){imageFiles.value.forEach(f=>data.append('images[]',f));removed.value.forEach(id=>data.append('remove_creatives[]',String(id)));if(form.value.remove_image)data.append('remove_image','1');}
 return data;}
async function save(){if(busy.value)return;if(!form.value.name.trim()){error.value='Informe o nome da campanha.';return;}if(form.value.provider==='direct'&&!imageFiles.value.length&&!existing.value.length){error.value='Envie ao menos uma imagem.';return;}busy.value=true;error.value='';notice.value='';try{await http.post(editing.value?`/dev/ads/${editing.value}`:'/dev/ads',payload(),{timeout:30000});close();await load();notice.value='Campanha salva. A Home mostra apenas anúncios ativos no período configurado.';}catch(e){error.value=friendly(e);}finally{busy.value=false;}}
async function toggle(ad){if(busy.value)return;busy.value=true;error.value='';try{const data=payload({...ad,is_active:!ad.is_active});await http.post(`/dev/ads/${ad.id}`,data);await load();notice.value='Visibilidade atualizada.';}catch(e){error.value=friendly(e);}finally{busy.value=false;}}
async function remove(ad){if(busy.value||!window.confirm(`Remover a campanha "${ad.name}"? O histórico será preservado.`))return;busy.value=true;error.value='';try{await http.delete(`/dev/ads/${ad.id}`);await load();notice.value='Campanha removida.';}catch(e){error.value=friendly(e);}finally{busy.value=false;}}
const labelFor=p=>slots.find(s=>s.id===p)?.label||p;
onMounted(load);
</script>
<template>
<section class="ace-ads-admin">
  <div class="ace-ads-head"><p class="ace-module-hint">Quatro regiões na Home, com cinco posições independentes; antes dos planos, dois lados configuráveis.</p><button type="button" class="ace-ads-primary" @click="newAd()"><Plus :size="17"/> Nova campanha</button></div>
  <div class="ace-ads-placements"><article v-for="slot in slots" :key="slot.id"><span class="ace-ads-index">LOCAL DE PUBLICAÇÃO</span><strong>{{slot.label}}</strong><p>{{slot.hint}}</p><small>{{ads.filter(x=>x.placement===slot.id&&x.is_active).length}} campanha(s) ativa(s)</small><button type="button" @click="newAd(slot.id)">Criar neste espaço <Plus :size="15"/></button></article></div>
  <p class="ace-ads-note"><Megaphone :size="17"/> As posições sem anúncio ativo ficam invisíveis. Cada posição mostra a campanha de maior prioridade. Várias imagens na mesma campanha viram carrossel automático, sem ocupar mais espaço na Home.</p>
  <div class="ace-ads-list-head"><h3>Campanhas cadastradas</h3><span>{{countActive}} ativa(s) · {{ads.length}} total</span></div>
  <p v-if="error" class="ace-ads-alert err" role="alert">{{error}}</p><p v-if="notice" class="ace-ads-alert ok" role="status">{{notice}}</p>
  <div v-if="loading" class="ace-ads-empty">Carregando campanhas...</div>
  <div v-else-if="!ads.length" class="ace-ads-empty"><ImagePlus :size="28"/><strong>Sem campanhas cadastradas.</strong><span>A Home continua limpa até você publicar.</span></div>
  <div v-else class="ace-ads-items"><article v-for="ad in ads" :key="ad.id" class="ace-ads-row"><img v-if="ad.admin_images?.[0]?.url" :src="ad.admin_images[0].url" :alt="ad.name"/><span v-else class="ace-ads-provider">{{ad.provider==='google'?'Google':'HTML'}}</span><div class="ace-ads-row-info"><strong>{{ad.name}}</strong><small>{{labelFor(ad.placement)}} · {{ad.provider==='direct'?'Imagens / carrossel':ad.provider==='google'?'Google AdSense':'HTML incorporado'}}</small><small v-if="ad.provider==='direct'">{{ad.admin_images?.length||0}} imagem(ns)</small><small>Início: {{formatDate(ad.starts_at)}} · Término: {{formatDate(ad.ends_at)}} · Prioridade {{ad.priority}}</small></div><span class="ace-ads-status" :class="{live:ad.is_active}">{{ad.is_active?'Ativo':'Pausado'}}</span><div class="ace-ads-actions"><button type="button" :disabled="busy" :title="ad.is_active?'Pausar':'Ativar'" @click="toggle(ad)"><Eye v-if="ad.is_active" :size="18"/><EyeOff v-else :size="18"/></button><button type="button" :disabled="busy" title="Editar" @click="editAd(ad)"><Pencil :size="18"/></button><button type="button" :disabled="busy" title="Excluir" @click="remove(ad)"><Trash2 :size="18"/></button></div></article></div>
  <div v-if="editor" class="ace-ads-overlay" @click.self="close"><form class="ace-ads-editor" @submit.prevent="save"><header><div><small>ADMINISTRAÇÃO DEV</small><h3>{{editing?'Editar campanha':'Nova campanha'}}</h3></div><button type="button" @click="close" aria-label="Fechar"><X :size="20"/></button></header><div class="ace-ads-editor-body">
    <div class="ace-ads-form-grid"><label>Identificação interna *<input v-model="form.name" required maxlength="140" placeholder="Campanha de novembro"/></label><label>Posição na Home<select v-model="form.placement"><option v-for="slot in slots" :key="slot.id" :value="slot.id">{{slot.label}}</option></select></label></div>
    <div class="ace-ads-form-grid"><label>Tipo de anúncio<select v-model="form.provider"><option value="direct">Imagem / carrossel</option><option value="google">Google AdSense</option><option value="html">Tag HTML incorporada</option></select></label><label>Prioridade<input v-model.number="form.priority" type="number" min="0" max="9999" required/></label></div>
    <template v-if="form.provider==='direct'"><label>Imagens do anúncio (uma ou várias)<input type="file" multiple accept="image/jpeg,image/png,image/webp" @change="chooseImages"/><small>JPG, PNG ou WebP · máximo 4 MB cada · novas imagens serão adicionadas às existentes.</small></label>
      <div v-if="existing.length||imagePreviews.length" class="ace-ads-media-grid"><div v-for="img in existing" :key="`old-${img.id}`"><img :src="img.url" alt="Imagem existente"/><button type="button" @click="removeExisting(img)" aria-label="Remover imagem"><X :size="14"/></button></div><div v-for="(url,i) in imagePreviews" :key="url"><img :src="url" alt="Nova imagem"/><button type="button" @click="removeNew(i)" aria-label="Remover nova imagem"><X :size="14"/></button></div></div>
      <label>Descrição acessível da imagem (opcional)<input v-model="form.headline" maxlength="160" placeholder="Nome do anunciante"/></label><label>Link ao clicar (opcional)<input v-model="form.destination_url" maxlength="500" placeholder="exemplo.com.br ou /caminho"/><small>Não é necessário digitar https://.</small></label>
    </template>
    <template v-else-if="form.provider==='google'"><div class="ace-ads-google-note">Use os IDs oficiais do AdSense. O anúncio será carregado após o consentimento do visitante.</div><label>Publisher ID *<input v-model="form.google_client" required placeholder="ca-pub-1234567890123456" pattern="ca-pub-[0-9]{16}"/></label><label>Slot ID *<input v-model="form.google_slot" required placeholder="1234567890" pattern="[0-9]{8,20}"/></label></template>
    <template v-else><div class="ace-ads-google-note">Cole somente códigos de anunciantes confiáveis. O HTML roda dentro de um iframe isolado, sem acesso aos dados ou ao login do ACE. O visitante precisa autorizar o carregamento.</div><label>Código HTML do anúncio *<textarea v-model="form.html_code" rows="8" required maxlength="16000" placeholder="<div>...</div> ou tag de anúncio"/></label></template>
    <div class="ace-ads-form-grid"><label>Início (opcional)<input v-model="form.starts_at" type="datetime-local"/></label><label>Término (opcional)<input v-model="form.ends_at" type="datetime-local"/></label></div><label class="ace-ads-checkbox"><input v-model="form.is_active" type="checkbox"/> Publicar campanha dentro do período definido</label>
  </div><footer><button type="button" @click="close">Cancelar</button><button type="submit" class="ace-ads-primary" :disabled="busy">{{busy?'Salvando...':'Salvar campanha'}}</button></footer></form></div>
</section>
</template>
