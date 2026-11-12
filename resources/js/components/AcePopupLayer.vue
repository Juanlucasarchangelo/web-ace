<script setup>
import { onMounted, onUnmounted, ref } from 'vue';
import { http } from '../lib/http';
import AcePopupCard from './AcePopupCard.vue';

const props = defineProps({ placement: { type: String, required: true }, authenticated: { type: Boolean, default: false } });
const item = ref(null);
let disposed = false;
let timer = null;
let stopTrigger = () => {};

function localDay() { const d = new Date(); const two = v => String(v).padStart(2, '0'); return `${d.getFullYear()}-${two(d.getMonth()+1)}-${two(d.getDate())}`; }
function storageSeen(candidate) {
  const key = `ace.popup.${candidate.id}.${candidate.version || 0}`;
  try {
    if (candidate.frequency === 'once') return localStorage.getItem(key) === 'seen';
    if (candidate.frequency === 'day') return localStorage.getItem(key) === localDay();
    if (candidate.frequency === 'session') return sessionStorage.getItem(key) === 'seen';
  } catch { /* Bloqueio de storage não impede o uso da página. */ }
  return false;
}
function remember(candidate) {
  const key = `ace.popup.${candidate.id}.${candidate.version || 0}`;
  try {
    if (candidate.frequency === 'once') localStorage.setItem(key, 'seen');
    if (candidate.frequency === 'day') localStorage.setItem(key, localDay());
    if (candidate.frequency === 'session') sessionStorage.setItem(key, 'seen');
  } catch { /* Navegadores privados podem rejeitar storage. */ }
}
function show(candidate) {
  if (disposed || item.value) return;
  stopTrigger();
  item.value = candidate;
  remember(candidate);
}
function arm(candidate) {
  const mode = candidate.trigger;
  if (mode === 'immediate') { show(candidate); return; }
  if (mode === 'delay') { timer = setTimeout(() => show(candidate), Math.max(0, Number(candidate.delay_seconds || 0)) * 1000); return; }
  if (mode === 'scroll') {
    const check = () => {
      const extent = document.documentElement.scrollHeight - window.innerHeight;
      if (extent > 0 && window.scrollY / extent * 100 >= Number(candidate.scroll_percent || 40)) show(candidate);
    };
    window.addEventListener('scroll', check, { passive: true });
    stopTrigger = () => window.removeEventListener('scroll', check);
    if (document.documentElement.scrollHeight <= window.innerHeight) timer = setTimeout(() => show(candidate), 5000);
    else check();
    return;
  }
  if (mode === 'exit') {
    if (window.matchMedia('(pointer: coarse)').matches) {
      timer = setTimeout(() => show(candidate), 5500); return;
    }
    const leaving = event => { if (!event.relatedTarget && event.clientY <= 8) show(candidate); };
    document.addEventListener('mouseout', leaving);
    stopTrigger = () => document.removeEventListener('mouseout', leaving);
  }
}
function close() { item.value = null; }
function esc(event) { if (event.key === 'Escape' && item.value) close(); }
onMounted(async () => {
  document.addEventListener('keydown', esc);
  try {
    const url = props.authenticated ? '/popups/active' : '/public/popups';
    const response = await http.get(url, { params: { placement: props.placement }, timeout: 10000 });
    if (disposed) return;
    const candidate = (response.data?.data || []).find(value => !storageSeen(value));
    if (candidate) arm(candidate);
  } catch { /* Falha comercial nunca bloqueia login, home ou dashboard. */ }
});
onUnmounted(() => {
  disposed = true;
  if (timer !== null) clearTimeout(timer);
  stopTrigger();
  document.removeEventListener('keydown', esc);
});
</script>
<template>
  <div v-if="item" class="ace-popup-layer" :class="{'ace-popup-layer-notice':item.type==='notice'}" @click.self="close" role="presentation">
    <div class="ace-popup-dialog" role="dialog" aria-modal="true" :aria-label="item.title || 'Comunicado ACE'">
      <AcePopupCard :item="item" @close="close" />
    </div>
  </div>
</template>
