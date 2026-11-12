<script setup>
import { onMounted, reactive, ref } from 'vue';
import { Mail, ShieldCheck, Save, Send, RefreshCw, CheckCircle2, AlertCircle, LockKeyhole } from 'lucide-vue-next';
import { http } from '../lib/http';
import { serverError } from '../lib/ace';

const loading=ref(true), saving=ref(false), testing=ref(false), error=ref(''),notice=ref('');
const state=reactive({enabled:false,configured:false,from_name:'ACE',from_email:'',resend_api_key:'',event_preferences:{},event_types:{},statistics:{},deliveries:[]});
const testEmail=ref('');const clearKey=ref(false);const diagnosing=ref(false);const diagnosis=ref(null);
const fmtDate=x=>x?new Date(x).toLocaleString('pt-BR'):'—';
async function load(){loading.value=true;error.value='';diagnosis.value=null;try{const {data}=await http.get('/dev/messaging');Object.assign(state,{...data.data,resend_api_key:''});}catch(e){error.value=serverError(e);}finally{loading.value=false;}}
async function save(){if(saving.value)return;saving.value=true;error.value='';notice.value='';try{
 const payload={enabled:state.enabled,from_name:state.from_name,from_email:state.from_email,event_preferences:state.event_preferences};
 if(state.resend_api_key.trim())payload.resend_api_key=state.resend_api_key.trim();
 if(clearKey.value){if(!confirm('Remover a chave Resend? Os envios serão interrompidos até inserir outra chave.'))return;payload.clear_key=true;payload.enabled=false;}
 const {data}=await http.put('/dev/messaging',payload);notice.value=data.message;clearKey.value=false;await load();}
 catch(e){error.value=serverError(e);}finally{saving.value=false;}}
async function diagnose(){if(diagnosing.value)return;diagnosing.value=true;diagnosis.value=null;error.value='';try{const {data}=await http.post('/dev/messaging/diagnose');diagnosis.value=data.data;}catch(e){error.value=serverError(e);}finally{diagnosing.value=false;}}
async function test(){if(testing.value)return;testing.value=true;error.value='';notice.value='';try{const {data}=await http.post('/dev/messaging/test',{email:testEmail.value.trim()});notice.value=data.message;}
 catch(e){error.value=serverError(e);}finally{testing.value=false;}}
onMounted(load);
</script>
<template>
  <section class="ace-support ace-messaging">
    <header class="ace-support-head"><div><div class="ace-support-eyebrow"><Mail :size="15"/> SERVIÇO DE ENTREGA</div><h2>Mensageria<span>.</span></h2><p>Notificações transacionais pelo Resend, com envios rastreados e destinatários definidos no Perfil DEV.</p></div><button class="ace-support-btn secondary" type="button" @click="load" :disabled="loading"><RefreshCw :size="15"/> Atualizar</button></header>
    <p v-if="error" class="ace-support-alert error" role="alert"><AlertCircle :size="17"/> {{error}}</p><p v-if="notice" class="ace-support-alert success" role="status"><CheckCircle2 :size="17"/> {{notice}}</p>
    <div v-if="!loading" class="ace-messaging-grid">
      <div class="ace-support-card"><div class="ace-support-card-title"><h3><LockKeyhole :size="18"/> Integração Resend</h3><span class="ace-support-chip" :class="state.configured&&state.enabled?'answered':'closed'">{{state.configured&&state.enabled?'Ativa':'Não configurada / pausada'}}</span></div>
        <p class="ace-support-muted">Use uma API Key do Resend e um remetente pertencente a um domínio verificado. A chave é armazenada criptografada e nunca reaparece na tela.</p>
        <form class="ace-support-form" @submit.prevent="save"><label>Nome do remetente<input v-model.trim="state.from_name" required minlength="2" maxlength="120" placeholder="ACE Suporte"/></label>
          <label>E-mail remetente (domínio verificado no Resend)<input v-model.trim="state.from_email" type="email" required maxlength="255" placeholder="notificacoes@seudominio.com.br"/></label>
          <label>API Key Resend <small>{{state.configured?'Chave salva · deixe em branco para manter a atual':'Informe sua chave começando com re_'}}</small><input v-model="state.resend_api_key" type="password" autocomplete="new-password" placeholder="re_..."/></label>
          <label class="ace-messaging-check"><input type="checkbox" v-model="state.enabled"/> <span>Ativar envio automático de notificações</span></label>
          <label v-if="state.configured" class="ace-messaging-check warning"><input type="checkbox" v-model="clearKey"/> <span>Remover a chave salva e desativar a integração</span></label>
          <button type="submit" class="ace-support-btn primary" :disabled="saving"><Save :size="15"/> {{saving?'Salvando...':'Salvar configuração'}}</button></form>
        <div style="margin-top:20px;padding-top:18px;border-top:1px solid rgba(150,180,190,.18)">
          <div class="ace-support-card-title"><h3><ShieldCheck :size="18"/> Diagnóstico da conexão</h3></div>
          <p class="ace-support-muted">Verifica a chave e o domínio remetente diretamente no Resend. Não envia e-mail nem altera suas configurações.</p>
          <button type="button" class="ace-support-btn secondary" :disabled="diagnosing||saving" @click="diagnose"><RefreshCw :size="15"/> {{diagnosing?'Verificando...':'Verificar conexão Resend'}}</button>
          <p v-if="diagnosis" class="ace-support-alert" :class="diagnosis.status==='connected'?'success':'error'" role="status" style="margin-top:12px"><CheckCircle2 v-if="diagnosis.status==='connected'" :size="17"/><AlertCircle v-else :size="17"/> {{diagnosis.message}}</p>
          <p class="ace-support-muted">A conexão confirmada não garante entrega na caixa de entrada. Use o botão “Enviar teste” ao lado para validar um destinatário.</p>
        </div>
      </div>
      <div class="ace-support-card"><div class="ace-support-card-title"><h3><ShieldCheck :size="18"/> Eventos monitorados</h3></div>
        <p class="ace-support-muted">Os alertas DEV vão para os endereços salvos em <strong>Meu perfil → Notificações DEV</strong>, independentemente do e-mail usado para entrar.</p>
        <div class="ace-messaging-event" v-for="(name,id) in state.event_types" :key="id"><label><input type="checkbox" v-model="state.event_preferences[id]"/> {{name}}</label></div>
        <p class="ace-support-muted">Pagamentos e renovações são notificados apenas após confirmação do gateway. Os vencimentos são checados diariamente.</p>
        <form class="ace-support-form" @submit.prevent="test"><label>Enviar e-mail de teste (destinatário deve constar no seu Perfil DEV)<input v-model.trim="testEmail" required type="email" maxlength="255" placeholder="financeiro@empresa.com.br"/></label><button class="ace-support-btn secondary" type="submit" :disabled="testing||!state.configured||!state.enabled"><Send :size="15"/> {{testing?'Enviando...':'Enviar teste'}}</button></form>
      </div>
    </div>
    <div v-if="!loading" class="ace-support-card ace-messaging-history"><div class="ace-support-card-title"><h3>Histórico de entregas</h3></div>
      <div class="ace-messaging-stats"><span>Aguardando configuração/destinatários <strong>{{state.statistics.waiting||0}}</strong></span><span>Na fila / para tentar novamente <strong>{{state.statistics.pending||0}}</strong></span><span>Aceitos pelo Resend <strong>{{state.statistics.sent||0}}</strong></span></div>
      <div class="ace-messaging-log"><table><thead><tr><th>Evento</th><th>Destinatário</th><th>Situação</th><th>Última tentativa</th></tr></thead><tbody><tr v-for="d in state.deliveries" :key="d.id"><td>{{state.event_types[d.event_type]||d.event_type}}</td><td>{{d.recipient_email}}</td><td><span class="ace-support-chip" :class="d.status==='sent'?'answered':'open'">{{d.status==='sent'?'Aceito pelo Resend':d.status==='failed'?'Falha, será tentado novamente':'Pendente'}}</span><small v-if="d.last_error">{{d.last_error}}</small></td><td>{{fmtDate(d.sent_at||d.next_attempt_at||d.created_at)}}</td></tr><tr v-if="!state.deliveries.length"><td colspan="4">Nenhum envio realizado até agora.</td></tr></tbody></table></div>
      <p class="ace-support-muted">“Aceito pelo Resend” indica aceitação pela API, não confirmação de leitura ou entrega na caixa postal.</p>
    </div>
  </section>
</template>
