<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Building2, Save, ShieldCheck, MapPin, Phone, FileText, CreditCard, Users, LockKeyhole, RotateCcw, CheckCircle2, AlertCircle, Search, LoaderCircle, Info } from 'lucide-vue-next';
import { http } from '../lib/http';
import { serverError } from '../lib/ace';

const props = defineProps({ tenantId: String, tenantName: String, role: String, companies: Array });
const emit = defineEmits(['created']);
const fieldKeys = ['name','legal_name','trade_name','document_number','contact_email','phone','postal_code','street','street_number','address_complement','district','city','state','legal_representative'];
const company = reactive(Object.fromEntries(fieldKeys.map(key => [key, ''])));
company.document_type = 'CNPJ';
const original = ref({});
const saving = ref(false);
const lookingUp = ref(false);
const error = ref('');
const success = ref('');
const cepMessage = ref('');
const currentTenant = computed(() => props.companies?.find(c => c.id === props.tenantId) || {});
const canEdit = computed(() => ['owner','admin'].includes(props.role));
const dirty = computed(() => JSON.stringify(Object.fromEntries([...fieldKeys,'document_type'].map(key => [key,company[key] ?? '']))) !== JSON.stringify(original.value));
const planLabel = computed(() => String(currentTenant.value.plan || 'free').toUpperCase());
const digits = value => String(value || '').replace(/\D/g, '');
const formatCpf = value => digits(value).slice(0,11).replace(/^(\d{3})(\d)/,'$1.$2').replace(/^(\d{3})\.(\d{3})(\d)/,'$1.$2.$3').replace(/\.(\d{3})(\d)/,'.$1-$2');
const formatCnpj = value => digits(value).slice(0,14).replace(/^(\d{2})(\d)/,'$1.$2').replace(/^(\d{2})\.(\d{3})(\d)/,'$1.$2.$3').replace(/\.(\d{3})(\d)/,'.$1/$2').replace(/(\d{4})(\d)/,'$1-$2');
const formatDocument = value => company.document_type === 'CPF' ? formatCpf(value) : formatCnpj(value);
const formatPhone = value => { const d=digits(value).slice(0,11); if(!d) return ''; if(d.length<=2) return `(${d}`; if(d.length<=6) return `(${d.slice(0,2)}) ${d.slice(2)}`; return d.length<=10 ? `(${d.slice(0,2)}) ${d.slice(2,6)}-${d.slice(6)}` : `(${d.slice(0,2)}) ${d.slice(2,7)}-${d.slice(7)}`; };
const formatCep = value => digits(value).slice(0,8).replace(/^(\d{5})(\d)/,'$1-$2');
function hydrate() {
  const data = currentTenant.value;
  for (const key of fieldKeys) company[key] = data[key] ?? (key === 'name' ? props.tenantName || '' : '');
  company.document_type = data.document_type || 'CNPJ';
  company.document_number = formatDocument(company.document_number);
  company.phone = formatPhone(company.phone);
  company.postal_code = formatCep(company.postal_code);
  original.value = Object.fromEntries([...fieldKeys,'document_type'].map(key => [key, company[key] ?? '']));
  error.value = ''; success.value = ''; cepMessage.value = '';
}
watch(() => [props.tenantId, props.tenantName, props.companies], hydrate, { immediate:true });
function changeDocumentType(event) { company.document_type = event.target.value; company.document_number = ''; error.value = ''; }
function resetForm(){ for(const key of [...fieldKeys,'document_type']) company[key] = original.value[key] ?? ''; error.value='';success.value='';cepMessage.value=''; }
function validate() {
  if (company.name.trim().length < 2) return 'Informe um nome da empresa com pelo menos 2 caracteres.';
  const doc = digits(company.document_number);
  if (doc && doc.length !== (company.document_type === 'CPF' ? 11 : 14)) return `O ${company.document_type} deve ter ${company.document_type === 'CPF' ? 11 : 14} dígitos.`;
  if (company.postal_code && digits(company.postal_code).length !== 8) return 'O CEP deve ter 8 dígitos.';
  if (company.phone && ![10,11].includes(digits(company.phone).length)) return 'Informe um telefone com DDD válido.';
  if (company.contact_email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(company.contact_email.trim())) return 'Informe um e-mail de contato válido.';
  if (company.state && !/^[A-Za-z]{2}$/.test(company.state.trim())) return 'A UF deve ter 2 letras.';
  return '';
}
async function lookupCep(){
  if (!canEdit.value) return;
  cepMessage.value='';
  const cep=digits(company.postal_code);
  if(cep.length!==8){ cepMessage.value='Digite os 8 números do CEP.';return; }
  lookingUp.value=true;
  try {
    const res=await fetch(`https://viacep.com.br/ws/${cep}/json/`,{headers:{Accept:'application/json'}});
    if(!res.ok) throw new Error('Serviço de CEP indisponível.');
    const result=await res.json();
    if(result.erro) {cepMessage.value='CEP não encontrado. Confira o número ou preencha o endereço manualmente.';return;}
    company.street=result.logradouro || company.street;
    company.district=result.bairro || company.district;
    company.city=result.localidade || company.city;
    company.state=result.uf || company.state;
    cepMessage.value='Endereço encontrado. Confira o número e o complemento.';
  } catch {cepMessage.value='Não foi possível consultar o CEP agora. Preencha o endereço manualmente.';}
  finally {lookingUp.value=false;}
}
async function save(){
  if(!canEdit.value || saving.value || !dirty.value) return;
  error.value='';success.value='';
  const invalid=validate();if(invalid){error.value=invalid;return;}
  saving.value=true;
  try {
    const payload=Object.fromEntries(fieldKeys.map(key=>[key,typeof company[key]==='string' ? company[key].trim() || null : null]));
    payload.name=company.name.trim();
    payload.document_type=digits(company.document_number) ? company.document_type : null;
    await http.put('/workspaces/current',payload);
    original.value=Object.fromEntries([...fieldKeys,'document_type'].map(key=>[key,company[key] ?? '']));
    success.value='Dados da empresa salvos com sucesso.';
    emit('created');
  } catch(e){error.value=e.response?.data?.errors ? Object.values(e.response.data.errors).flat().join(' ') : serverError(e);}
  finally{saving.value=false;}
}
</script>

<template>
  <div class="ace-page company-page">
    <header class="ace-page-head company-page-head">
      <div>
        <div class="ace-eyebrow"><span class="ace-eyebrow-dot"></span> CONTA E CONTRATAÇÃO</div>
        <h1>Dados da empresa<span>.</span></h1>
        <p>Gerencie as informações cadastrais, contatos e endereço da empresa vinculada ao ACE.</p>
      </div>
      <div class="company-header-icon" aria-hidden="true"><Building2 :size="27" :stroke-width="1.6"/></div>
    </header>

    <div class="company-layout">
      <main class="company-main">
        <div v-if="error" class="company-alert company-alert-error" role="alert"><AlertCircle :size="18"/><span>{{error}}</span></div>
        <div v-if="success" class="company-alert company-alert-success" role="status"><CheckCircle2 :size="18"/><span>{{success}}</span></div>

        <form class="company-form" @submit.prevent="save" novalidate>
          <section class="company-section">
            <div class="company-section-header">
              <div class="company-section-symbol"><Building2 :size="20"/></div>
              <div><div class="company-section-kicker">01 · INFORMAÇÕES CADASTRAIS</div><h2>Identificação da empresa</h2><p>Dados utilizados para identificar o contratante.</p></div>
            </div>
            <div class="company-grid">
              <div class="company-field company-span-2"><label for="ace-company-name">Nome da empresa no ACE <span class="company-required">*</span></label><input id="ace-company-name" v-model="company.name" autocomplete="organization" placeholder="Como sua empresa aparece no sistema" maxlength="140" :readonly="!canEdit"/><small>Nome exibido na interface e no ambiente da empresa.</small></div>
              <div class="company-field"><label for="ace-company-legal">Razão social / nome completo</label><input id="ace-company-legal" v-model="company.legal_name" placeholder="Nome jurídico do contratante" maxlength="180" :readonly="!canEdit"/></div>
              <div class="company-field"><label for="ace-company-trade">Nome fantasia</label><input id="ace-company-trade" v-model="company.trade_name" placeholder="Nome comercial da empresa" maxlength="180" :readonly="!canEdit"/></div>
              <div class="company-field"><label for="ace-company-doc-type">Tipo de documento</label><select id="ace-company-doc-type" :value="company.document_type" :disabled="!canEdit" @change="changeDocumentType"><option value="CNPJ">CNPJ · Pessoa jurídica</option><option value="CPF">CPF · Pessoa física</option></select></div>
              <div class="company-field"><label for="ace-company-doc">{{company.document_type}}</label><input id="ace-company-doc" :value="company.document_number" :placeholder="company.document_type==='CPF'?'000.000.000-00':'00.000.000/0000-00'" inputmode="numeric" :maxlength="company.document_type==='CPF'?14:18" :readonly="!canEdit" @input="company.document_number=formatDocument($event.target.value)"/><small>O documento é validado ao salvar.</small></div>
            </div>
          </section>

          <section class="company-section">
            <div class="company-section-header"><div class="company-section-symbol"><Phone :size="20"/></div><div><div class="company-section-kicker">02 · COMUNICAÇÃO</div><h2>Contato e responsável</h2><p>Informações para comunicação com sua empresa.</p></div></div>
            <div class="company-grid">
              <div class="company-field"><label for="ace-company-email">E-mail de contato</label><input id="ace-company-email" v-model="company.contact_email" type="email" autocomplete="email" placeholder="contato@empresa.com.br" maxlength="255" :readonly="!canEdit"/></div>
              <div class="company-field"><label for="ace-company-phone">Telefone com DDD</label><input id="ace-company-phone" :value="company.phone" inputmode="tel" autocomplete="tel" placeholder="(11) 99999-9999" maxlength="15" :readonly="!canEdit" @input="company.phone=formatPhone($event.target.value)"/></div>
              <div class="company-field company-span-2"><label for="ace-company-representative">Responsável legal</label><input id="ace-company-representative" v-model="company.legal_representative" placeholder="Nome completo do representante" maxlength="140" :readonly="!canEdit"/></div>
            </div>
          </section>

          <section class="company-section">
            <div class="company-section-header"><div class="company-section-symbol"><MapPin :size="20"/></div><div><div class="company-section-kicker">03 · LOCALIZAÇÃO</div><h2>Endereço da empresa</h2><p>Informe o endereço cadastral do contratante.</p></div></div>
            <div class="company-grid">
              <div class="company-field company-span-2"><label for="ace-company-cep">CEP</label><div class="company-cep-row"><input id="ace-company-cep" :value="company.postal_code" inputmode="numeric" autocomplete="postal-code" placeholder="00000-000" maxlength="9" :readonly="!canEdit" @input="company.postal_code=formatCep($event.target.value);cepMessage=''" @blur="canEdit && digits(company.postal_code).length===8 && !company.street && lookupCep()"/><button v-if="canEdit" type="button" class="company-cep-btn" :disabled="lookingUp || digits(company.postal_code).length!==8" @click="lookupCep"><LoaderCircle v-if="lookingUp" :size="15" class="company-spin"/><Search v-else :size="15"/> {{lookingUp?'Consultando...':'Buscar CEP'}}</button></div><small v-if="cepMessage" class="company-cep-feedback">{{cepMessage}}</small><small v-else>Consulte o CEP para preencher rua, bairro, cidade e UF automaticamente.</small></div>
              <div class="company-field company-span-2"><label for="ace-company-street">Logradouro</label><input id="ace-company-street" v-model="company.street" autocomplete="address-line1" placeholder="Rua, avenida, alameda..." maxlength="180" :readonly="!canEdit"/></div>
              <div class="company-field"><label for="ace-company-number">Número</label><input id="ace-company-number" v-model="company.street_number" placeholder="Número ou S/N" maxlength="20" :readonly="!canEdit"/></div>
              <div class="company-field"><label for="ace-company-complement">Complemento</label><input id="ace-company-complement" v-model="company.address_complement" autocomplete="address-line2" placeholder="Sala, conjunto, andar..." maxlength="100" :readonly="!canEdit"/></div>
              <div class="company-field"><label for="ace-company-district">Bairro</label><input id="ace-company-district" v-model="company.district" placeholder="Bairro" maxlength="100" :readonly="!canEdit"/></div>
              <div class="company-field"><label for="ace-company-city">Cidade</label><input id="ace-company-city" v-model="company.city" autocomplete="address-level2" placeholder="Cidade" maxlength="100" :readonly="!canEdit"/></div>
              <div class="company-field company-state"><label for="ace-company-uf">UF</label><input id="ace-company-uf" :value="company.state" maxlength="2" autocomplete="address-level1" placeholder="SP" :readonly="!canEdit" @input="company.state=$event.target.value.replace(/[^a-zA-Z]/g,'').slice(0,2).toUpperCase()"/></div>
            </div>
          </section>

          <footer class="company-actions">
            <div class="company-actions-note"><ShieldCheck :size="17"/><span v-if="canEdit">Suas alterações só são aplicadas ao clicar em salvar.</span><span v-else>Somente o Master pode alterar os dados cadastrais.</span></div>
            <div v-if="canEdit" class="company-buttons"><button type="button" class="company-reset" :disabled="saving || !dirty" @click="resetForm"><RotateCcw :size="15"/> Descartar</button><button class="ace-btn ace-btn-primary company-save" type="submit" :disabled="saving || !dirty"><LoaderCircle v-if="saving" :size="16" class="company-spin"/><Save v-else :size="16"/> {{saving?'Salvando dados...':'Salvar alterações'}}</button></div>
          </footer>
        </form>
      </main>

      <aside class="company-aside">
        <div class="company-summary">
          <div class="company-summary-top"><div class="company-summary-emblem"><Building2 :size="22"/></div><span class="company-summary-caption">EMPRESA VINCULADA</span></div>
          <h3>{{company.name || tenantName || 'Sua empresa'}}</h3>
          <div class="company-summary-line"><CreditCard :size="16"/><span>Plano contratado</span><strong>{{planLabel}}</strong></div>
          <div class="company-summary-line"><Users :size="16"/><span>Limite de usuários</span><strong>{{currentTenant.seat_limit ?? '—'}}</strong></div>
          <div class="company-summary-line"><ShieldCheck :size="16"/><span>Situação</span><strong>{{currentTenant.is_active===false?'Inativa':'Vinculada'}}</strong></div>
        </div>
        <div class="company-policy"><div class="company-policy-icon"><LockKeyhole :size="18"/></div><h4>Uma conta, uma empresa</h4><p>Esta assinatura ACE está vinculada a uma única empresa. Para contratar o serviço para outra empresa, é necessário criar outro cadastro e assinatura.</p></div>
        <div class="company-tip"><Info :size="17"/><p>Os campos não obrigatórios podem ser completados depois. Confira especialmente o documento e o e-mail de contato.</p></div>
      </aside>
    </div>
  </div>
</template>

<style scoped>
.company-page{--company-edge:var(--ace-border,#e1e9ec);--company-text:var(--ace-text,#213843);--company-muted:var(--ace-muted,#8197a3);--company-surface:var(--ace-panel,#fff);--company-soft:#f5faf9;max-width:1440px;margin:0 auto;padding-bottom:35px}
.company-page-head{margin-bottom:25px}.company-page-head p{max-width:560px}.company-header-icon{width:54px;height:54px;display:grid;place-items:center;background:rgba(35,154,128,.1);border:1px solid rgba(35,154,128,.18);color:#27a58b;border-radius:16px;flex:none}
.company-layout{display:grid;grid-template-columns:minmax(0,1fr) 285px;gap:22px;align-items:start}.company-main{min-width:0}.company-form{border:1px solid var(--company-edge);border-radius:19px;background:var(--company-surface);overflow:hidden;box-shadow:0 10px 34px rgba(15,43,55,.035)}
.company-section{padding:29px 32px 31px}.company-section+.company-section{border-top:1px solid var(--company-edge)}.company-section-header{display:flex;align-items:center;gap:15px;margin-bottom:25px}.company-section-symbol{width:45px;height:45px;flex:none;border-radius:13px;display:grid;place-items:center;color:#2aa78f;background:rgba(41,170,144,.1)}.company-section-kicker{font-size:10px;font-weight:800;letter-spacing:.1em;color:#33a992;margin-bottom:5px}.company-section-header h2{font-size:19px;font-weight:760;letter-spacing:-.025em;line-height:1.25;margin:0;color:var(--company-text)}.company-section-header p{font-size:12px;line-height:1.5;color:var(--company-muted);margin:5px 0 0}
.company-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px 18px}.company-field{display:flex;flex-direction:column;gap:8px;min-width:0}.company-span-2{grid-column:span 2}.company-field label{font-size:12px;font-weight:720;line-height:1.3;color:var(--company-text)}.company-required{color:#26a68b}.company-field input,.company-field select{display:block;width:100%;min-width:0;height:46px;border:1px solid var(--company-edge);border-radius:10px;background:transparent;color:var(--company-text);font:inherit;font-size:13px;padding:0 14px;outline:none;transition:border-color .15s,box-shadow .15s;appearance:auto}.company-field input::placeholder{color:var(--company-muted);opacity:.72}.company-field input:focus,.company-field select:focus{border-color:#30a88e;box-shadow:0 0 0 3px rgba(48,168,142,.13)}.company-field input[readonly],.company-field select:disabled{opacity:.76;cursor:default}.company-field select option{color:#1c3441;background:#fff}.company-field small{font-size:11px;line-height:1.55;color:var(--company-muted)}.company-cep-row{display:flex;gap:9px;max-width:420px}.company-cep-row input{flex:1}.company-cep-btn{white-space:nowrap;border:1px solid rgba(48,168,142,.32);background:rgba(48,168,142,.09);border-radius:10px;color:#2da38a;font-size:12px;font-weight:750;padding:0 14px;display:flex;align-items:center;gap:7px;cursor:pointer}.company-cep-btn:hover:not(:disabled){background:rgba(48,168,142,.17)}.company-cep-btn:disabled{opacity:.5;cursor:default}.company-cep-feedback{color:#299b85!important}.company-state{max-width:160px}
.company-actions{border-top:1px solid var(--company-edge);background:rgba(125,154,167,.035);padding:20px 32px;display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap}.company-actions-note{display:flex;align-items:center;gap:9px;color:var(--company-muted);font-size:11px;max-width:280px;line-height:1.4}.company-actions-note svg{flex:none;color:#2b9b83}.company-buttons{display:flex;align-items:center;gap:10px}.company-reset{border:1px solid var(--company-edge);border-radius:10px;padding:12px 15px;background:transparent;color:var(--company-text);font-size:12px;font-weight:700;display:flex;align-items:center;gap:7px;cursor:pointer}.company-reset:disabled{opacity:.4;cursor:default}.company-save{font-size:12px;padding:13px 18px;min-height:43px}.company-spin{animation:company-spin 1s linear infinite}@keyframes company-spin{to{transform:rotate(360deg)}}
.company-aside{position:sticky;top:20px;display:flex;flex-direction:column;gap:14px}.company-summary{border:1px solid var(--company-edge);border-radius:17px;background:var(--company-surface);padding:23px}.company-summary-top{display:flex;align-items:center;gap:10px;margin-bottom:17px}.company-summary-emblem{width:38px;height:38px;background:rgba(48,168,142,.1);color:#2da68e;display:grid;place-items:center;border-radius:11px}.company-summary-caption{color:var(--company-muted);font-size:10px;font-weight:800;letter-spacing:.095em}.company-summary h3{font-size:17px;line-height:1.4;letter-spacing:-.02em;font-weight:760;color:var(--company-text);margin:0 0 20px;overflow-wrap:anywhere}.company-summary-line{display:flex;align-items:center;gap:9px;padding:13px 0;border-top:1px solid var(--company-edge);font-size:11px;color:var(--company-muted)}.company-summary-line svg{color:#39a78e;flex:none}.company-summary-line span{flex:1}.company-summary-line strong{color:var(--company-text);font-weight:780}.company-policy{padding:23px;border-radius:17px;border:1px solid rgba(45,158,136,.2);background:rgba(41,160,138,.075)}.company-policy-icon{color:#2daa90;margin-bottom:12px}.company-policy h4{font-size:13px;color:var(--company-text);margin:0 0 9px;font-weight:790}.company-policy p,.company-tip p{font-size:11px;color:var(--company-muted);line-height:1.75;margin:0}.company-tip{display:flex;gap:11px;padding:5px 10px;color:var(--company-muted)}.company-tip svg{color:#2ba68e;flex:none;margin-top:2px}.company-alert{display:flex;align-items:flex-start;gap:10px;padding:13px 16px;border-radius:11px;margin-bottom:15px;font-size:12px;line-height:1.5}.company-alert svg{flex:none}.company-alert-error{background:rgba(212,67,80,.10);color:#e16c74;border:1px solid rgba(212,67,80,.2)}.company-alert-success{background:rgba(39,161,126,.10);color:#2da58b;border:1px solid rgba(39,161,126,.2)}
html[data-ace-theme="dark"] .company-page{--company-edge:#354957;--company-text:#e6edf2;--company-muted:#98aaba;--company-surface:#1c2b39}html[data-ace-theme="dark"] .company-form{box-shadow:0 14px 34px rgba(3,12,20,.1)}html[data-ace-theme="dark"] .company-section-symbol,html[data-ace-theme="dark"] .company-summary-emblem{background:rgba(43,171,141,.12)}
@media(max-width:1100px){.company-layout{grid-template-columns:minmax(0,1fr) 250px;gap:16px}.company-section{padding:25px}.company-actions{padding:18px 25px}}
@media(max-width:850px){.company-layout{grid-template-columns:1fr}.company-aside{position:static;display:grid;grid-template-columns:1fr 1fr}.company-tip{grid-column:span 2}.company-header-icon{display:none}}
@media(max-width:580px){.company-section{padding:22px 18px}.company-section-header{align-items:flex-start;gap:11px}.company-section-symbol{width:38px;height:38px}.company-section-header h2{font-size:17px}.company-grid{grid-template-columns:1fr;gap:17px}.company-span-2{grid-column:auto}.company-state{max-width:none}.company-aside{grid-template-columns:1fr}.company-tip{grid-column:auto}.company-actions{padding:18px;align-items:stretch;flex-direction:column}.company-buttons{width:100%}.company-reset,.company-save{flex:1}.company-cep-row{max-width:unset}.company-cep-btn{padding:0 11px}}
</style>
