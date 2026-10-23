<script setup>
import { onMounted, reactive, ref, watch } from 'vue';
import { Plus, Search, UsersRound, Pencil, Trash2, Building2, Globe2, X, Save, ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { http } from '../lib/http';
import { serverError, cloudNames, clientStatus } from '../lib/ace';
const props = defineProps({ canEdit: { type: Boolean, default: false } });
const emit = defineEmits(['navigate']);
const search = ref(''), filter = ref(''), records = ref([]), loading = ref(false), page = ref(1), pages = ref(1);
const dialog = ref(false), saving = ref(false), error = ref(''), listError = ref('');
const empty = () => ({ code: '', name:'', trade_name:'', person_type:'company', document:'', email:'', phone:'', mobile:'', contact_name:'', contact_email:'', address_street:'', address_number:'', address_extra:'', address_district:'', address_city:'', address_state:'', address_zip:'', address_country:'Brasil', started_at:'', status:'active', preferred_cloud:null, notes:'' });
const form = reactive(empty());
const basics = [{key:'name', label:'Nome / Razão social', required:true}, {key:'code',label:'ID / código interno'}, {key:'trade_name',label:'Nome fantasia'}, {key:'document',label:'CPF ou CNPJ'}, {key:'email',label:'E-mail principal',type:'email'}, {key:'phone',label:'Telefone'}, {key:'mobile',label:'Celular / WhatsApp'}, {key:'contact_name',label:'Responsável / contato'}, {key:'contact_email',label:'E-mail do contato',type:'email'}, {key:'started_at',label:'Início do relacionamento',type:'date'}];
const address = [{key:'address_zip',label:'CEP'}, {key:'address_street',label:'Logradouro'}, {key:'address_number',label:'Número'}, {key:'address_extra',label:'Complemento'}, {key:'address_district',label:'Bairro'}, {key:'address_city',label:'Cidade'}, {key:'address_state',label:'UF / Estado'}, {key:'address_country',label:'País'}];
const clouds = [{title:'Não definido',value:null}, ...Object.entries(cloudNames).map(([value,title])=>({title,value}))];
let timeout;
async function load() {
    loading.value = true; listError.value = '';
    try { const {data} = await http.get('/clients', {params:{search:search.value, status:filter.value, page:page.value}}); records.value=data.data.data; pages.value=data.data.last_page; }
    catch(e) { listError.value=serverError(e); }
    finally { loading.value=false; }
}
watch([search,filter], () => { page.value=1; clearTimeout(timeout); timeout=setTimeout(load,280); });
watch(page,load);
onMounted(load);
async function open(item=null) {
    error.value=''; Object.assign(form, empty()); delete form.id;
    if(item) { try { const {data}=await http.get(`/clients/${item.id}`); Object.assign(form,data.data); } catch(e) { listError.value=serverError(e); return; } }
    dialog.value=true;
}
function cleanForm() { const body={}; for(const key of Object.keys(empty())) body[key] = form[key] === '' ? null : form[key]; body.name=form.name; body.person_type=form.person_type; body.status=form.status; return body; }
async function save() {
    if (!props.canEdit) return;
    error.value=''; saving.value=true;
    try { const id=form.id; if(id) await http.put(`/clients/${id}`,cleanForm()); else await http.post('/clients',cleanForm()); dialog.value=false; await load(); }
    catch(e) { error.value=serverError(e); }
    finally { saving.value=false; }
}
async function remove() {
    if(!form.id || !confirm(`Excluir permanentemente o cliente ${form.name}?`)) return;
    error.value=''; try { await http.delete(`/clients/${form.id}`); dialog.value=false; await load(); } catch(e){error.value=serverError(e);}
}
</script>
<template>
<div class="ace-page">
    <div class="ace-page-head"><div><div class="ace-eyebrow"><span class="ace-eyebrow-dot"></span> RELACIONAMENTO</div><h1>Clientes<span>.</span></h1><p>Gerencie sua carteira, contatos, documentos e informações comerciais.</p></div><button v-if="canEdit" class="ace-btn ace-btn-primary" @click="open()"><Plus :size="17"/> Novo cliente</button></div>
    <section class="ace-panel ace-table-panel"><div class="ace-table-toolbar"><div class="ace-search"><Search :size="17"/><input v-model="search" placeholder="Buscar por cliente, código ou documento..." aria-label="Buscar clientes"/></div><select v-model="filter" class="ace-filter"><option value="">Todos os status</option><option value="active">Ativos</option><option value="inactive">Inativos</option></select></div>
    <div v-if="listError" class="ace-error">{{listError}}</div><div class="ace-table-wrap"><table class="ace-table"><thead><tr><th>CLIENTE</th><th>DOCUMENTO</th><th>CONTATO</th><th>SITES</th><th>STATUS</th><th></th></tr></thead><tbody><tr v-for="item in records" :key="item.id" @click="open(item)"><td><div class="ace-name-cell"><span class="ace-initial-icon"><Building2 :size="17"/></span><div><strong>{{item.name}}</strong><small>{{item.code || `#${item.id}`}} · {{item.trade_name || 'Cliente'}}</small></div></div></td><td>{{item.document || '—'}}</td><td><span class="ace-cell-long">{{item.email || item.phone || '—'}}</span></td><td><span class="ace-count"><Globe2 :size="14"/>{{item.sites_count}}</span></td><td><span class="ace-status" :class="item.status">{{clientStatus[item.status]}}</span></td><td><button class="ace-icon-button" aria-label="Abrir cliente"><Pencil :size="16"/></button></td></tr><tr v-if="!loading && !records.length"><td colspan="6"><div class="ace-empty-state"><UsersRound :size="26"/><strong>Nenhum cliente encontrado</strong><span>Cadastre seu primeiro cliente ou ajuste os filtros.</span></div></td></tr></tbody></table></div><div class="ace-pagination"><span>Página {{page}} de {{pages}}</span><div><button :disabled="page===1" @click="page--"><ChevronLeft :size="18"/></button><button :disabled="page>=pages" @click="page++"><ChevronRight :size="18"/></button></div></div></section>
    <v-dialog v-model="dialog" max-width="880" scrollable><div class="ace-dialog"><div class="ace-dialog-head"><div><div class="ace-eyebrow">CADASTRO DE CLIENTE</div><h2>{{form.id?'Editar cliente':'Novo cliente'}}</h2><p>Dados comerciais e preferências futuras de integração.</p></div><button class="ace-icon-button" aria-label="Fechar" @click="dialog=false"><X :size="21"/></button></div><div class="ace-dialog-body"><div v-if="error" class="ace-error">{{error}}</div>
        <div class="ace-form-section"><div class="ace-form-title">Dados principais</div><div class="ace-form-grid"><v-select v-model="form.person_type" label="Tipo de pessoa" :items="[{title:'Pessoa jurídica',value:'company'},{title:'Pessoa física',value:'person'}]" :readonly="!canEdit"/><v-select v-model="form.status" label="Status" :items="[{title:'Ativo',value:'active'},{title:'Inativo',value:'inactive'}]" :readonly="!canEdit"/><v-text-field v-for="f in basics" :key="f.key" v-model="form[f.key]" :label="f.label" :type="f.type||'text'" :readonly="!canEdit" :required="!!f.required"/></div></div>
        <div class="ace-form-section"><div class="ace-form-title">Endereço</div><div class="ace-form-grid"><v-text-field v-for="f in address" :key="f.key" v-model="form[f.key]" :label="f.label" :readonly="!canEdit"/></div></div>
        <div class="ace-form-section"><div class="ace-form-title">Integração de nuvem <span class="ace-later">Preparação para o futuro</span></div><p class="ace-form-help">Selecione o serviço preferido do cliente. Nenhuma autorização de nuvem é realizada nesta versão.</p><v-select v-model="form.preferred_cloud" label="Provedor de preferência" :items="clouds" :readonly="!canEdit" clearable/></div>
        <div class="ace-form-section"><div class="ace-form-title">Informações adicionais</div><v-textarea v-model="form.notes" label="Anotações internas" :readonly="!canEdit" rows="3"/></div>
        </div><div class="ace-dialog-actions"><button v-if="form.id && canEdit" class="ace-btn ace-btn-danger" @click="remove"><Trash2 :size="16"/> Excluir</button><span class="ace-flex-spacer"></span><button class="ace-btn ace-btn-outline" @click="dialog=false">Fechar</button><button v-if="canEdit" class="ace-btn ace-btn-primary" :disabled="saving" @click="save"><Save :size="16"/>{{saving?'Salvando...':'Salvar cliente'}}</button></div></div></v-dialog>
</div>
</template>
