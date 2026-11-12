<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import {
  UsersRound, UserRoundPlus, ShieldCheck, Shield, LockKeyhole, LogOut, Trash2, Search,
  Save, X, KeyRound, CheckCircle2, Ban, RefreshCw, AlertTriangle, Eye, EyeOff
} from 'lucide-vue-next';
import { http } from '../lib/http';
import { serverError, roleNames } from '../lib/ace';

const list = ref([]);
const usage = ref({seat_limit:1,seats_used:0,can_manage:false});
const search = ref('');
const loading = ref(false);
const busy = ref(false);
const error = ref('');
const notice = ref('');
const showCreate = ref(false);
const showPassword = ref(false);
const passwordUser = ref(null);
const form = reactive({name:'',email:'',password:'',role:'member'});
const credentials = reactive({password:'',password_confirmation:''});
const showInitialPassword = ref(false);
const showResetPassword = ref(false);
const roles = [{title:'Administrador',value:'admin'}, {title:'Membro',value:'member'}];

const results = computed(() => list.value.filter(user =>
  `${user.name} ${user.email} ${roleNames[user.role] || user.role}`.toLocaleLowerCase('pt-BR')
    .includes(search.value.trim().toLocaleLowerCase('pt-BR'))
));
const admins = computed(() => list.value.filter(u => ['owner','admin'].includes(u.role)).length);
const active = computed(() => list.value.filter(u => u.is_active).length);
const hasSeats = computed(() => usage.value.seats_used < usage.value.seat_limit);

function resetMessages(){error.value='';notice.value='';}
function notify(message){notice.value=message;error.value='';}
async function load(){
  loading.value=true;error.value='';
  try {const {data}=await http.get('/team'); list.value=data.data;usage.value=data.meta || usage.value;}
  catch(e){error.value=serverError(e);} finally{loading.value=false;}
}
function create(){
  resetMessages(); Object.assign(form,{name:'',email:'',password:'',role:'member'});
  showInitialPassword.value=false;showCreate.value=true;
}
async function submitCreate(){
  if(busy.value)return;
  busy.value=true;error.value='';
  try{
    await http.post('/team',form);
    showCreate.value=false;notify('Novo usuário criado. Envie os dados de acesso por um canal seguro.');
    await load();notify('Novo usuário criado. Envie os dados de acesso por um canal seguro.');
  }catch(e){error.value=serverError(e);}finally{busy.value=false;}
}
async function changeRole(user,value){
  if(value===user.role || !value)return;
  if(!confirm(`Alterar o cargo de ${user.name} para ${roleNames[value]}? As sessões abertas serão encerradas.`)) return;
  await execute(()=>http.put(`/team/${user.id}`,{role:value}),'Cargo atualizado. Sessões antigas encerradas.');
}
async function toggle(user){
  const next=!user.is_active;
  if(!confirm(`${next?'Reativar':'Bloquear'} ${user.name}? ${next?'O usuário poderá voltar a entrar.':'As sessões abertas serão encerradas.'}`))return;
  await execute(()=>http.patch(`/team/${user.id}/status`,{is_active:next}),next?'Usuário reativado.':'Usuário bloqueado e desconectado.');
}
async function disconnect(user){
  if(!confirm(`Encerrar todas as sessões de ${user.name}?`))return;
  await execute(()=>http.post(`/team/${user.id}/logout`),'Sessões encerradas.');
}
function openPassword(user){
  passwordUser.value=user;credentials.password='';credentials.password_confirmation='';
  showResetPassword.value=false;resetMessages();showPassword.value=true;
}
async function resetPassword(){
  if(!passwordUser.value)return;
  await execute(()=>http.put(`/team/${passwordUser.value.id}/password`,credentials),
    'Senha redefinida. O usuário terá de entrar novamente.',()=>{showPassword.value=false;passwordUser.value=null;});
}
async function remove(user){
  if(!confirm(`REMOVER ${user.name} desta empresa? O login será desvinculado, mas os registros e históricos da empresa serão preservados.`))return;
  await execute(()=>http.delete(`/team/${user.id}`),'Acesso removido da empresa. Histórico preservado.');
}
async function execute(callback,message,onSuccess){
  if(busy.value)return;
  busy.value=true;resetMessages();
  try{await callback();onSuccess?.();await load();notify(message);}
  catch(e){error.value=serverError(e);}finally{busy.value=false;}
}
onMounted(load);
</script>

<template>
  <div class="ace-page account-team-page">
    <header class="ace-page-head account-page-heading">
      <div>
        <div class="ace-eyebrow"><span class="ace-eyebrow-dot"></span> GESTÃO DE ACESSOS</div>
        <h1>Usuários e permissões<span>.</span></h1>
        <p>Gerencie a equipe da sua empresa com segurança e controle de sessões.</p>
      </div>
      <button v-if="usage.can_manage" type="button" class="ace-btn ace-btn-primary" :disabled="!hasSeats || busy" @click="create">
        <UserRoundPlus :size="18"/> Adicionar usuário
      </button>
    </header>

    <div v-if="error" class="ace-error" role="alert">{{error}}</div>
    <div v-if="notice" class="account-success" role="status"><CheckCircle2 :size="18"/> {{notice}}</div>

    <section class="account-stats">
      <div class="account-stat"><span class="account-stat-icon"><UsersRound :size="21"/></span><div><small>Usuários cadastrados</small><strong>{{usage.seats_used}} <span>/ {{usage.seat_limit}}</span></strong><p>Vagas do plano contratado</p></div></div>
      <div class="account-stat"><span class="account-stat-icon"><ShieldCheck :size="21"/></span><div><small>Gestores</small><strong>{{admins}}</strong><p>Master e Administradores</p></div></div>
      <div class="account-stat"><span class="account-stat-icon"><CheckCircle2 :size="21"/></span><div><small>Acessos ativos</small><strong>{{active}}</strong><p>Usuários habilitados</p></div></div>
    </section>

    <section class="account-main-panel">
      <div class="account-panel-heading">
        <div><h2>Equipe da empresa</h2><p>Os dados são exclusivos desta empresa. O Master não pode ser removido nem rebaixado.</p></div>
        <button class="account-icon-btn" type="button" :disabled="loading" title="Atualizar lista" @click="load"><RefreshCw :size="18"/></button>
      </div>
      <div class="account-toolbar">
        <label class="account-search"><Search :size="17"/><input v-model="search" type="search" placeholder="Buscar nome, e-mail ou cargo..." aria-label="Buscar usuários"/></label>
        <span class="account-result-count">{{results.length}} usuário(s)</span>
      </div>
      <div class="account-table-scroll">
        <table class="account-table">
          <thead><tr><th>USUÁRIO</th><th>PERFIL</th><th>SITUAÇÃO</th><th class="account-actions-column">GERENCIAR</th></tr></thead>
          <tbody>
            <tr v-for="user in results" :key="user.id">
              <td><div class="account-person"><span class="account-person-avatar">{{user.name?.slice(0,1)?.toUpperCase()}}</span><span><strong>{{user.name}} <small v-if="user.is_self">(você)</small></strong><small>{{user.email}}</small></span></div></td>
              <td>
                <span v-if="user.role==='owner'" class="account-role-master"><ShieldCheck :size="14"/> Master</span>
                <select v-else-if="!user.is_self" class="account-role-select" :value="user.role" :disabled="busy" :aria-label="`Cargo de ${user.name}`" @change="changeRole(user,$event.target.value);$event.target.value=user.role">
                  <option v-for="item in roles" :key="item.value" :value="item.value">{{item.title}}</option>
                </select>
                <span v-else class="account-role-tag">{{roleNames[user.role]||user.role}}</span>
              </td>
              <td><span class="account-status" :class="user.is_active?'is-active':'is-blocked'"><span></span>{{user.is_active?'Ativo':'Bloqueado'}}</span></td>
              <td>
                <div v-if="!user.is_self && user.role!=='owner'" class="account-row-actions">
                  <button type="button" title="Trocar a senha" aria-label="Trocar senha" :disabled="busy" @click="openPassword(user)"><KeyRound :size="17"/></button>
                  <button type="button" title="Deslogar de todos os dispositivos" aria-label="Encerrar sessões" :disabled="busy" @click="disconnect(user)"><LogOut :size="17"/></button>
                  <button type="button" :title="user.is_active?'Bloquear usuário':'Reativar usuário'" :aria-label="user.is_active?'Bloquear':'Reativar'" :disabled="busy" @click="toggle(user)"><Ban v-if="user.is_active" :size="17"/><CheckCircle2 v-else :size="17"/></button>
                  <button type="button" class="is-danger" title="Remover acesso da empresa" aria-label="Remover usuário" :disabled="busy" @click="remove(user)"><Trash2 :size="17"/></button>
                </div>
                <span v-else class="account-protected"><Shield :size="14"/> Protegido</span>
              </td>
            </tr>
            <tr v-if="!loading && !results.length"><td colspan="4"><div class="account-table-empty">Nenhum usuário encontrado.</div></td></tr>
          </tbody>
        </table>
      </div>
      <div class="account-panel-footer"><LockKeyhole :size="17"/><span>Bloquear ou trocar um cargo encerra as sessões antigas. Remover um acesso nunca apaga clientes, sites, arquivos ou histórico.</span></div>
    </section>

    <section class="account-roles">
      <article><div class="account-role-symbol"><ShieldCheck :size="19"/></div><div><h3>Master</h3><p>Contratante e responsável principal. Gerencia a assinatura, a empresa e todos os usuários.</p></div></article>
      <article><div class="account-role-symbol"><Shield :size="19"/></div><div><h3>Administrador</h3><p>Gerencia usuários, cadastros e acessos. O Master permanece protegido.</p></div></article>
      <article><div class="account-role-symbol"><UsersRound :size="19"/></div><div><h3>Membro</h3><p>Consulta e cria clientes, sites, credenciais e arquivos na nuvem. Não administra a equipe ou o plano.</p></div></article>
    </section>

    <v-dialog v-model="showCreate" max-width="600">
      <form class="account-modal" @submit.prevent="submitCreate">
        <header><div><div class="ace-eyebrow">NOVA CONTA</div><h2>Adicionar usuário</h2><p>Este usuário ficará vinculado somente à empresa atual.</p></div><button class="account-icon-btn" type="button" aria-label="Fechar" @click="showCreate=false"><X :size="20"/></button></header>
        <div class="account-modal-body">
          <div v-if="error" class="ace-error">{{error}}</div>
          <label class="account-field">Nome completo<input v-model.trim="form.name" maxlength="120" minlength="2" required autocomplete="off" placeholder="Nome do colaborador"/></label>
          <label class="account-field">E-mail de acesso<input v-model.trim="form.email" type="email" maxlength="255" required autocomplete="off" placeholder="email@empresa.com.br"/></label>
          <div class="account-field"><label for="create-password">Senha inicial</label><div class="account-password-input"><input id="create-password" v-model="form.password" :type="showInitialPassword?'text':'password'" minlength="12" maxlength="128" required autocomplete="new-password" placeholder="Mínimo de 12 caracteres"/><button type="button" :aria-label="showInitialPassword?'Ocultar senha':'Mostrar senha'" @click="showInitialPassword=!showInitialPassword"><EyeOff v-if="showInitialPassword" :size="17"/><Eye v-else :size="17"/></button></div></div>
          <label class="account-field">Perfil de acesso<select v-model="form.role" required><option v-for="item in roles" :key="item.value" :value="item.value">{{item.title}}</option></select></label>
          <p class="account-help"><ShieldCheck :size="16"/> A senha não será exibida novamente. O usuário poderá alterá-la em Meu perfil.</p>
        </div>
        <footer><button type="button" class="ace-btn ace-btn-outline" @click="showCreate=false">Cancelar</button><button type="submit" class="ace-btn ace-btn-primary" :disabled="busy || !hasSeats"><Save :size="17"/> {{busy?'Criando...':'Criar usuário'}}</button></footer>
      </form>
    </v-dialog>

    <v-dialog v-model="showPassword" max-width="560">
      <form class="account-modal" @submit.prevent="resetPassword">
        <header><div><div class="ace-eyebrow">SEGURANÇA DA CONTA</div><h2>Redefinir senha</h2><p>{{passwordUser?.name}} · {{passwordUser?.email}}</p></div><button class="account-icon-btn" type="button" aria-label="Fechar" @click="showPassword=false"><X :size="20"/></button></header>
        <div class="account-modal-body">
          <div v-if="error" class="ace-error">{{error}}</div>
          <div class="account-field"><label for="new-team-password">Nova senha</label><div class="account-password-input"><input id="new-team-password" v-model="credentials.password" :type="showResetPassword?'text':'password'" required minlength="12" maxlength="128" autocomplete="new-password" placeholder="Mínimo de 12 caracteres"/><button type="button" :aria-label="showResetPassword?'Ocultar senha':'Mostrar senha'" @click="showResetPassword=!showResetPassword"><EyeOff v-if="showResetPassword" :size="17"/><Eye v-else :size="17"/></button></div></div>
          <label class="account-field">Confirmar nova senha<input v-model="credentials.password_confirmation" :type="showResetPassword?'text':'password'" required minlength="12" maxlength="128" autocomplete="new-password" placeholder="Repita a nova senha"/></label>
          <p class="account-help"><AlertTriangle :size="16"/> A senha anterior deixará de funcionar e todas as sessões desse usuário serão encerradas.</p>
        </div>
        <footer><button type="button" class="ace-btn ace-btn-outline" @click="showPassword=false">Cancelar</button><button type="submit" class="ace-btn ace-btn-primary" :disabled="busy || !credentials.password || credentials.password!==credentials.password_confirmation"><KeyRound :size="17"/> {{busy?'Salvando...':'Alterar senha'}}</button></footer>
      </form>
    </v-dialog>
  </div>
</template>
