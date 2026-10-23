<script setup>
import { ref, onMounted, watch } from 'vue';
import { KeyRound, LockKeyhole, Search, ArrowRight, ChevronLeft, ChevronRight, ShieldCheck } from 'lucide-vue-next';
import { http } from '../lib/http';
import { categoryNames, serverError } from '../lib/ace';
const emit = defineEmits(['navigate']);
const items = ref([]), page = ref(1), pages = ref(1), search = ref(''), category = ref(''), loading = ref(false), error = ref('');
let timer;
async function load() {
  loading.value=true;error.value='';
  try { const { data } = await http.get('/vault', {params:{page:page.value,search:search.value,category:category.value}});
    items.value=data.data.data;pages.value=data.data.last_page;
  } catch(e) {error.value=serverError(e)} finally {loading.value=false}
}
watch([search,category],()=>{page.value=1;clearTimeout(timer);timer=setTimeout(load,250)});
watch(page,load);onMounted(load);
</script>
<template>
  <div class="ace-page"><div class="ace-page-head"><div><div class="ace-eyebrow"><span class="ace-eyebrow-dot"></span> GESTÃO DE SEGREDOS</div><h1>Cofre de acessos<span>.</span></h1><p>Pesquisa centralizada de credenciais cadastradas nos sites e clientes.</p></div><span class="ace-quiet-mark"><LockKeyhole :size="18"/> Segredos criptografados</span></div>
    <section class="ace-panel ace-vault-intro"><div class="ace-vault-intro-icon"><KeyRound :size="26"/></div><div><h2>Todos os acessos, em um único lugar.</h2><p>As senhas não aparecem nas listagens. Abra o site correspondente para gerenciar ou revelar um segredo com permissão e auditoria.</p></div><button class="ace-btn ace-btn-primary" @click="emit('navigate','sites')">Sites <ArrowRight :size="16"/></button></section>
    <section class="ace-panel ace-table-panel"><div class="ace-table-toolbar"><div class="ace-search"><Search :size="17"/><input v-model="search" placeholder="Buscar serviço, usuário, site ou domínio..." aria-label="Buscar credencial"/></div><select v-model="category" class="ace-filter"><option value="">Todas as categorias</option><option v-for="(label,key) in categoryNames" :key="key" :value="key">{{ label }}</option></select></div>
    <div v-if="error" class="ace-error">{{error}}</div><div class="ace-table-wrap"><table class="ace-table"><thead><tr><th>CREDENCIAL</th><th>PLATAFORMA / USUÁRIO</th><th>SITE</th><th>CLIENTE</th><th></th></tr></thead><tbody><tr v-for="item in items" :key="item.id" @click="emit('navigate','sites',item.site_id)"><td><div class="ace-name-cell"><span class="ace-initial-icon"><KeyRound :size="17"/></span><div><strong>{{item.label}}</strong><small>{{categoryNames[item.category]||item.category}}</small></div></div></td><td><strong>{{item.service||'—'}}</strong><div>{{item.username||'Sem usuário'}}</div></td><td>{{item.site?.domain||'—'}}</td><td>{{item.site?.client?.name||'—'}}</td><td><ArrowRight :size="16"/></td></tr><tr v-if="!loading&&!items.length"><td colspan="5"><div class="ace-empty-state"><ShieldCheck :size="26"/><strong>Nenhum acesso encontrado</strong><span>Adicione credenciais dentro das fichas dos sites.</span></div></td></tr></tbody></table></div>
    <div class="ace-pagination"><span>Página {{page}} de {{pages}}</span><div><button :disabled="page===1" @click="page--"><ChevronLeft :size="18"/></button><button :disabled="page>=pages" @click="page++"><ChevronRight :size="18"/></button></div></div></section>
  </div>
</template>
