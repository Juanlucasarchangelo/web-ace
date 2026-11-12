<script setup>
import { onMounted, reactive, ref } from 'vue';
import { Camera, UserRound, Mail, Phone, BriefcaseBusiness, Save, LockKeyhole, ShieldCheck,
  Trash2, CheckCircle2, AlertTriangle, Eye, EyeOff, Upload, LoaderCircle } from 'lucide-vue-next';
import { http } from '../lib/http';
import { serverError } from '../lib/ace';

const emit=defineEmits(['updated']);
const loading=ref(true), saving=ref(false), savingPassword=ref(false), uploading=ref(false);
const error=ref(''),notice=ref(''),passwordError=ref(''),passwordNotice=ref('');
const profile=reactive({name:'',email:'',phone:'',job_title:'',avatar_url:null,has_avatar:false,is_dev:false});
const password=reactive({current_password:'',password:'',password_confirmation:''});
const reveal=ref(false),avatarVersion=ref(Date.now()),picker=ref(null);
const photoUrl=()=>profile.has_avatar?`${profile.avatar_url}?v=${avatarVersion.value}`:null;
function clearMessages(){error.value='';notice.value='';}
function applyProfile(data){Object.assign(profile,data);avatarVersion.value=Date.now();emit('updated');}
async function load(){loading.value=true;try{applyProfile((await http.get('/auth/profile')).data.data)}catch(e){error.value=serverError(e)}finally{loading.value=false}}
async function save(){if(saving.value)return;saving.value=true;clearMessages();
 try{const {data}=await http.put('/auth/profile',{name:profile.name,email:profile.email,phone:profile.phone||null,job_title:profile.job_title||null});applyProfile(data.data);notice.value='Seus dados foram atualizados.'}
 catch(e){error.value=serverError(e)}finally{saving.value=false}}
async function changePassword(){if(savingPassword.value)return;savingPassword.value=true;passwordError.value='';passwordNotice.value='';
 try{const {data}=await http.put('/auth/profile/password',password);Object.assign(password,{current_password:'',password:'',password_confirmation:''});passwordNotice.value=data.message||'Senha alterada.'}
 catch(e){passwordError.value=serverError(e)}finally{savingPassword.value=false}}
async function uploadAvatar(event){const file=event.target.files?.[0];event.target.value='';if(!file)return;
 if(!['image/jpeg','image/png','image/webp'].includes(file.type)||file.size>2*1024*1024){error.value='Use JPG, PNG ou WebP de até 2 MB.';return;}
 uploading.value=true;clearMessages();try{const form=new FormData();form.append('avatar',file);const {data}=await http.post('/auth/profile/avatar',form,{timeout:40000});applyProfile(data.data);notice.value='Foto de perfil atualizada.'}
 catch(e){error.value=serverError(e)}finally{uploading.value=false}}
async function deleteAvatar(){if(!confirm('Remover sua foto de perfil?'))return;uploading.value=true;clearMessages();
 try{const {data}=await http.delete('/auth/profile/avatar');applyProfile(data.data);notice.value='Foto removida.'}
 catch(e){error.value=serverError(e)}finally{uploading.value=false}}
onMounted(load);
</script>
<template>
  <div class="ace-page account-profile-page">
    <header class="ace-page-head account-page-heading"><div><div class="ace-eyebrow"><span class="ace-eyebrow-dot"></span> CONTA PESSOAL</div><h1>Meu perfil<span>.</span></h1><p>Atualize seus dados pessoais, sua foto e a segurança do seu acesso.</p></div></header>
    <div v-if="error" class="ace-error" role="alert">{{error}}</div>
    <div v-if="notice" class="account-success" role="status"><CheckCircle2 :size="18"/> {{notice}}</div>
    <div class="account-profile-grid">
      <div class="account-profile-main">
        <section class="account-main-panel">
          <div class="account-panel-heading"><div><h2>Informações pessoais</h2><p>Os dados abaixo identificam sua conta ACE.</p></div><UserRound :size="21"/></div>
          <form class="account-form" @submit.prevent="save">
            <div class="account-form-grid">
              <label class="account-field">Nome completo<input v-model.trim="profile.name" required minlength="2" maxlength="120" autocomplete="name" placeholder="Seu nome"/></label>
              <label class="account-field">E-mail de login<input v-model.trim="profile.email" type="email" required maxlength="255" autocomplete="email" placeholder="nome@empresa.com.br"/></label>
              <label class="account-field">Telefone com DDD<input v-model.trim="profile.phone" type="tel" maxlength="20" autocomplete="tel" placeholder="(11) 99999-9999"/></label>
              <label class="account-field">Cargo / função<input v-model.trim="profile.job_title" maxlength="100" autocomplete="organization-title" placeholder="Ex.: Coordenação de projetos"/></label>
            </div>
            <div class="account-form-footer"><span><ShieldCheck :size="16"/> Seus dados ficam vinculados ao seu login.</span><button type="submit" class="ace-btn ace-btn-primary" :disabled="saving || loading"><Save :size="16"/> {{saving?'Salvando...':'Salvar dados'}}</button></div>
          </form>
        </section>
        <section class="account-main-panel">
          <div class="account-panel-heading"><div><h2>Segurança e senha</h2><p>Altere sua senha usando a senha atual como confirmação.</p></div><LockKeyhole :size="21"/></div>
          <div v-if="passwordError" class="ace-error" role="alert">{{passwordError}}</div>
          <div v-if="passwordNotice" class="account-success" role="status"><CheckCircle2 :size="18"/> {{passwordNotice}}</div>
          <form class="account-form" @submit.prevent="changePassword">
            <div class="account-form-grid">
              <label class="account-field account-full">Senha atual<input v-model="password.current_password" :type="reveal?'text':'password'" required autocomplete="current-password" placeholder="Digite sua senha atual"/></label>
              <label class="account-field">Nova senha<input v-model="password.password" :type="reveal?'text':'password'" required minlength="12" maxlength="128" autocomplete="new-password" placeholder="Mínimo de 12 caracteres"/></label>
              <label class="account-field">Confirme a nova senha<input v-model="password.password_confirmation" :type="reveal?'text':'password'" required minlength="12" maxlength="128" autocomplete="new-password" placeholder="Repita a nova senha"/></label>
            </div>
            <div class="account-form-footer"><button class="account-text-btn" type="button" @click="reveal=!reveal"><EyeOff v-if="reveal" :size="16"/><Eye v-else :size="16"/> {{reveal?'Ocultar senhas':'Mostrar senhas'}}</button><button type="submit" class="ace-btn ace-btn-primary" :disabled="savingPassword || password.password!==password.password_confirmation"><LockKeyhole :size="16"/> {{savingPassword?'Atualizando...':'Alterar senha'}}</button></div>
            <p class="account-password-note"><AlertTriangle :size="15"/> Após alterar sua senha, outros dispositivos conectados serão desconectados.</p>
          </form>
        </section>
      </div>
      <aside class="account-profile-aside">
        <section class="account-main-panel account-avatar-panel">
          <div class="account-panel-heading"><div><h2>Foto de perfil</h2><p>Personalize a identificação da sua conta.</p></div><Camera :size="21"/></div>
          <div class="account-avatar-preview"><img v-if="profile.has_avatar" :src="photoUrl()" alt="Sua foto de perfil"/><span v-else>{{profile.name?.slice(0,1)?.toUpperCase() || '?'}}</span></div>
          <strong class="account-profile-name">{{profile.name || 'Usuário ACE'}}</strong><span class="account-profile-email">{{profile.email}}</span>
          <input ref="picker" type="file" accept="image/jpeg,image/png,image/webp" hidden @change="uploadAvatar"/>
          <button type="button" class="ace-btn ace-btn-primary account-avatar-upload" :disabled="uploading" @click="picker?.click()"><Upload :size="17"/> {{uploading?'Enviando...':'Enviar foto'}}</button>
          <button v-if="profile.has_avatar" type="button" class="account-text-btn" :disabled="uploading" @click="deleteAvatar"><Trash2 :size="15"/> Remover foto</button>
          <small class="account-avatar-hint">JPG, PNG ou WebP. Máximo de 2 MB.</small>
        </section>
        <div class="account-profile-security"><ShieldCheck :size="21"/><div><strong>Conta protegida</strong><p>Sua senha é armazenada de forma segura. O acesso e suas alterações seguem as permissões da empresa.</p></div></div>
      </aside>
    </div>
  </div>
</template>
