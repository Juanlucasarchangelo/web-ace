<script setup>
import { onMounted, ref, reactive } from 'vue';
import { Plus, UsersRound, ShieldCheck, X, Save, Trash2 } from 'lucide-vue-next';
import { http } from '../lib/http';
import { serverError, roleNames } from '../lib/ace';
const props=defineProps({canEdit:Boolean});
const list=ref([]),err=ref(''),dialog=ref(false),saving=ref(false),formErr=ref('');
const form=reactive({name:'',email:'',password:'',role:'member'});
const roles=[{title:'Administrador — gerencia dados e acessos',value:'admin'}, {title:'Membro — leitura sem revelação',value:'member'}, {title:'Leitor — somente leitura',value:'viewer'}];
async function load(){try{list.value=(await http.get('/team')).data.data}catch(e){err.value=serverError(e)}}
onMounted(load);
function open(){Object.assign(form,{name:'',email:'',password:'',role:'member'});formErr.value='';dialog.value=true}
async function save(){saving.value=true;formErr.value='';try{await http.post('/team',form);dialog.value=false;await load()}catch(e){formErr.value=serverError(e)}finally{saving.value=false}}
async function setRole(user,e){try{await http.put(`/team/${user.id}`,{role:e.target.value});await load()}catch(e){err.value=serverError(e)}}
async function remove(user){if(!confirm(`Remover o acesso de ${user.name} a esta empresa?`))return;try{await http.delete(`/team/${user.id}`);await load()}catch(e){err.value=serverError(e)}}
</script>
<template><div class="ace-page"><div class="ace-page-head"><div><div class="ace-eyebrow"><span class="ace-eyebrow-dot"></span> GOVERNANÇA</div><h1>Usuários e permissões<span>.</span></h1><p>Controle quem pode consultar e administrar os dados da empresa.</p></div><button v-if="canEdit" class="ace-btn ace-btn-primary" @click="open"><Plus :size="16"/> Novo usuário</button></div><div v-if="err" class="ace-error">{{err}}</div>
<section class="ace-panel ace-table-panel"><div class="ace-table-wrap"><table class="ace-table"><thead><tr><th>MEMBRO</th><th>PERMISSÃO</th><th>STATUS</th><th></th></tr></thead><tbody><tr v-for="user in list" :key="user.id"><td><div class="ace-name-cell"><span class="ace-initial-icon">{{user.name.slice(0,1).toUpperCase()}}</span><div><strong>{{user.name}}</strong><small>{{user.email}}</small></div></div></td><td><select v-if="canEdit&&user.role!=='owner'" class="ace-filter" :value="user.role" @change="setRole(user,$event)"><option v-for="role in roles" :key="role.value" :value="role.value">{{roleNames[role.value]}}</option></select><span v-else>{{roleNames[user.role]||user.role}}</span></td><td><span class="ace-status" :class="user.is_active?'active':'inactive'">{{user.is_active?'Ativo':'Inativo'}}</span></td><td><button v-if="canEdit&&user.role!=='owner'" class="ace-icon-button" aria-label="Remover membro" @click="remove(user)"><Trash2 :size="16"/></button></td></tr></tbody></table></div></section>
<div class="ace-info-note ace-page-note"><ShieldCheck :size="17"/> Apenas proprietários e administradores podem cadastrar, editar ou revelar credenciais. A criação de usuários exige senha inicial; convites por e-mail serão adicionados posteriormente.</div>
<v-dialog v-model="dialog" max-width="590"><div class="ace-dialog"><div class="ace-dialog-head"><div><div class="ace-eyebrow">ADMINISTRAÇÃO</div><h2>Adicionar usuário</h2><p>Crie o acesso inicial para a equipe desta empresa.</p></div><button class="ace-icon-button" @click="dialog=false"><X :size="20"/></button></div><div class="ace-dialog-body"><div v-if="formErr" class="ace-error">{{formErr}}</div><v-text-field v-model="form.name" label="Nome completo"/><v-text-field v-model="form.email" label="E-mail de login" type="email"/><v-text-field v-model="form.password" label="Senha inicial (mínimo de 12 caracteres)" type="password" autocomplete="new-password"/><v-select v-model="form.role" label="Permissão" :items="roles"/></div><div class="ace-dialog-actions"><span class="ace-flex-spacer"></span><button class="ace-btn ace-btn-outline" @click="dialog=false">Cancelar</button><button class="ace-btn ace-btn-primary" :disabled="saving" @click="save"><Save :size="16"/>{{saving?'Salvando...':'Criar usuário'}}</button></div></div></v-dialog>
</div></template>
