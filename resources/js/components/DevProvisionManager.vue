<script setup>
import { onMounted, ref } from 'vue';
import { http } from '../lib/http';
import { serverError } from '../lib/ace';

const busy = ref(false);
const error = ref('');
const notice = ref('');
const tenants = ref([]);
const company = ref({company:'', owner_name:'', owner_email:'', owner_password:'', plan:'free', seat_limit:5});
const member = ref({tenant_id:'',name:'',email:'',password:'',role:'member'});
async function loadTenants() {
  try { const {data} = await http.get('/dev/tenants', {params:{page:1}}); tenants.value=data.data.data || []; }
  catch(e) { error.value=serverError(e); }
}
async function createCompany() {
  if(busy.value)return;
  if(!window.confirm('Criar empresa e conta Master com acesso imediato? Não registra pagamento.'))return;
  busy.value=true; error.value=''; notice.value='';
  try {
    const {data}=await http.post('/dev/provision/tenants', company.value);
    notice.value=`Empresa ${data.data.name} criada. Master: ${data.data.owner_email}.`;
    company.value={company:'',owner_name:'',owner_email:'',owner_password:'',plan:'free',seat_limit:5};
    await loadTenants();
  } catch(e) {error.value=e.response?.data?.errors?Object.values(e.response.data.errors).flat().join(' '):serverError(e);}
  finally { busy.value=false; }
}
async function createMember() {
  if(busy.value)return;
  if(!window.confirm('Adicionar este usuário à empresa selecionada?'))return;
  busy.value=true; error.value=''; notice.value='';
  try {
    const {data}=await http.post('/dev/provision/users', member.value);
    notice.value=`Usuário ${data.data.email} criado. Informe a senha ao usuário por canal privado.`;
    member.value={tenant_id:member.value.tenant_id,name:'',email:'',password:'',role:'member'};
  } catch(e) {error.value=e.response?.data?.errors?Object.values(e.response.data.errors).flat().join(' '):serverError(e);}
  finally {busy.value=false;}
}
onMounted(loadTenants);
</script>

<template>
  <section class="dev-panel ace-provision">
    <div class="dev-panel-header"><h2>Provisionamento interno DEV</h2></div>
    <p>Crie empresas, contas Master e membros diretamente. Esta operação não depende de SMTP e não confirma nenhum pagamento. Senhas nunca são exibidas novamente.</p>
    <p v-if="error" role="alert" class="dev-banner dev-error">{{error}}</p>
    <p v-if="notice" role="status" class="dev-banner dev-success">{{notice}}</p>
    <div class="ace-provision-grid">
      <form @submit.prevent="createCompany" class="ace-provision-form">
        <h3>Nova empresa + Master</h3>
        <label>Nome da empresa<input v-model.trim="company.company" required minlength="2" maxlength="140"/></label>
        <label>Nome do Master<input v-model.trim="company.owner_name" required minlength="2" maxlength="120"/></label>
        <label>E-mail do Master<input v-model.trim="company.owner_email" type="email" required maxlength="255"/></label>
        <label>Senha do Master<input v-model="company.owner_password" type="password" required minlength="12" autocomplete="new-password"/></label>
        <label>Plano inicial<select v-model="company.plan"><option value="free">Free</option><option value="cloud">Cloud (manual, não pago)</option><option value="pro">Pro (manual, não pago)</option></select></label>
        <label>Vagas da equipe<input v-model.number="company.seat_limit" type="number" required min="1" max="5000"/></label>
        <button class="dev-button-outline" type="submit" :disabled="busy">{{busy?'Criando…':'Criar empresa e Master'}}</button>
      </form>
      <form @submit.prevent="createMember" class="ace-provision-form">
        <h3>Adicionar usuário à empresa</h3>
        <label>Empresa<select v-model="member.tenant_id" required><option value="" disabled>Selecione</option><option v-for="t in tenants" :key="t.id" :value="t.id">{{t.name}} — {{t.members}}/{{t.seat_limit}} vagas</option></select></label>
        <label>Nome<input v-model.trim="member.name" required minlength="2" maxlength="120"/></label>
        <label>E-mail<input v-model.trim="member.email" type="email" required maxlength="255"/></label>
        <label>Senha<input v-model="member.password" type="password" required minlength="12" autocomplete="new-password"/></label>
        <label>Função<select v-model="member.role"><option value="admin">Administrador</option><option value="member">Membro</option><option value="viewer">Visualizador</option></select></label>
        <button class="dev-button-outline" type="submit" :disabled="busy">{{busy?'Criando…':'Criar usuário'}}</button>
      </form>
    </div>
    <p><small>Operações restritas ao DEV, registradas na auditoria. O DEV não recebe acesso aos dados internos das empresas.</small></p>
  </section>
</template>
