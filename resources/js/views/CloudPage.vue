<script setup>
import { Cloud, ShieldCheck, HardDrive, FolderSync, Info } from 'lucide-vue-next';
const providers=[
  {name:'Google Drive',type:'Armazenamento e arquivos'},
  {name:'Microsoft OneDrive',type:'Armazenamento e identidade'},
  {name:'Dropbox',type:'Armazenamento de arquivos'},
  {name:'Amazon S3',type:'Buckets e objetos'},
  {name:'iCloud',type:'Ecossistema Apple'},
  {name:'MEGA',type:'Armazenamento de arquivos'},
];
</script>
<template>
<div class="ace-page"><div class="ace-page-head"><div><div class="ace-eyebrow"><span class="ace-eyebrow-dot"></span> EXPANSÃO FUTURA</div><h1>Integrações em nuvem<span>.</span></h1><p>Planejamento de provedores de arquivos e serviços vinculados aos clientes.</p></div><span class="ace-quiet-mark"><Cloud :size="18"/> Planejamento</span></div>
<section class="ace-panel ace-vault-intro"><div class="ace-vault-intro-icon"><ShieldCheck :size="25"/></div><div><h2>Sem integrações ativas neste estágio.</h2><p>O cadastro do cliente já inclui seu provedor preferido. Nenhuma autorização OAuth2 é solicitada e nenhum arquivo externo é acessado automaticamente.</p></div></section>
<div class="ace-company-grid"><article v-for="provider in providers" :key="provider.name" class="ace-panel ace-company-card"><div class="ace-company-card-icon"><HardDrive :size="23"/></div><h2>{{ provider.name }}</h2><p>{{provider.type}}</p><div class="ace-company-card-foot"><span class="ace-status inactive">Ainda não conectado</span><FolderSync :size="17"/></div></article></div>
<div class="ace-info-note ace-page-note"><Info :size="16"/> Conectores, sincronização e permissões OAuth2 dependem de uma etapa específica de desenvolvimento e homologação.</div>
</div>
</template>
