<script setup>
import { onMounted, ref } from 'vue';
import { History, ShieldCheck, ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { http } from '../lib/http';
import { serverError,fmtDate } from '../lib/ace';
const events=ref([]),page=ref(1),pages=ref(1),error=ref('');
async function load(){try{const {data}=await http.get('/activity',{params:{page:page.value}});events.value=data.data.data;pages.value=data.data.last_page}catch(e){error.value=serverError(e)}}
function paginate(delta){page.value+=delta;load()}
onMounted(load);
</script>
<template><div class="ace-page"><div class="ace-page-head"><div><div class="ace-eyebrow"><span class="ace-eyebrow-dot"></span> HISTÓRICO SEGURO</div><h1>Auditoria<span>.</span></h1><p>Registros de ações e consultas sensíveis realizadas no seu workspace.</p></div><span class="ace-quiet-mark"><ShieldCheck :size="18"/> Rastreabilidade</span></div><div v-if="error" class="ace-error">{{error}}</div><section class="ace-panel ace-table-panel"><div class="ace-table-wrap"><table class="ace-table"><thead><tr><th>EVENTO</th><th>RESPONSÁVEL</th><th>DATA</th></tr></thead><tbody><tr v-for="event in events" :key="event.id"><td><div class="ace-name-cell"><span class="ace-initial-icon"><History :size="17"/></span><div><strong>{{event.action.replaceAll('.',' · ')}}</strong><small>Evento #{{event.id}}</small></div></div></td><td>{{event.user?.name || 'Sistema'}}</td><td>{{fmtDate(event.created_at)}}</td></tr><tr v-if="!events.length"><td colspan="3"><div class="ace-empty-small">Nenhuma atividade registrada.</div></td></tr></tbody></table></div><div class="ace-pagination"><span>Página {{page}} de {{pages}}</span><div><button :disabled="page===1" @click="paginate(-1)"><ChevronLeft :size="18"/></button><button :disabled="page>=pages" @click="paginate(1)"><ChevronRight :size="18"/></button></div></div></section><div class="ace-info-note ace-page-note">A auditoria registra a ação, usuário, horário e origem, mas nunca grava o conteúdo das senhas.</div></div></template>
