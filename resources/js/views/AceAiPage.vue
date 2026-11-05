<script setup>
import { Sparkles, ShieldCheck, PanelsTopLeft, FileKey2, UsersRound, Cloud } from 'lucide-vue-next';
import AceChatPanel from '../components/AceChatPanel.vue';
const props = defineProps({ page: { type: String, default: 'ia' } });
const emit = defineEmits(['navigate', 'updated']);
</script>
<template>
    <div class="ace-page ace-ai-premium-page">
        <div class="ace-page-head"><div><div class="ace-eyebrow"><span class="ace-eyebrow-dot"></span> INTELIGÊNCIA DO WORKSPACE</div><h1>ACE IA<span>.</span></h1><p>Seu copiloto para pesquisar, entender e operar o ACE com segurança.</p></div><span class="ace-chat-header-security"><ShieldCheck :size="15"/> Workspace protegido</span></div>
        <div class="ace-ai-premium-grid">
            <AceChatPanel mode="page" :page="page" @navigate="(...args) => emit('navigate', ...args)" @updated="emit('updated')" />
            <aside class="ace-ai-premium-side">
                <div class="ace-ai-premium-side-kicker"><Sparkles :size="16"/> ASSISTENTE CONECTADO</div>
                <h2>Mais do que responder. Ajudar a fazer.</h2>
                <p>O assistente compreende a tela em que você está e consulta os dados autorizados do workspace. Cadastros são preparados para sua aprovação.</p>
                <div class="ace-ai-premium-side-item"><PanelsTopLeft :size="18"/><span>Ajuda contextual em cada página</span></div>
                <div class="ace-ai-premium-side-item"><UsersRound :size="18"/><span>Clientes e sites em uma única conversa</span></div>
                <div class="ace-ai-premium-side-item"><FileKey2 :size="18"/><span>Cadastro estruturado de webmail e acessos</span></div>
                <div class="ace-ai-premium-side-item"><Cloud :size="18"/><span>Estado das integrações no ACE</span></div>
                <div class="ace-ai-premium-safety"><ShieldCheck :size="16"/><span>O modelo não recebe senhas, tokens ou documentos pessoais. As gravações são controladas pelo Laravel e auditadas.</span></div>
            </aside>
        </div>
    </div>
</template>
