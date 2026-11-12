<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';

const props = defineProps({ ad: { type: Object, default: null }, placement: { type: String, required: true } });
const consent = ref(false);
const googleError = ref(false);
const adElement = ref(null);
const current = ref(0);
const images = computed(() => props.ad?.images?.length ? props.ad.images : (props.ad?.image_url ? [{url:props.ad.image_url}] : []));
const active = computed(() => images.value[current.value] || images.value[0]);
let interval;
function stop() { if (interval) clearInterval(interval); interval = undefined; }
function start() {
  stop();
  if (images.value.length > 1) interval = setInterval(() => { current.value = (current.value + 1) % images.value.length; }, 5000);
}
function advance(dir) { current.value = (current.value + dir + images.value.length) % images.value.length; start(); }
watch(() => props.ad?.id, () => { current.value = 0; consent.value = false; googleError.value = false; start(); });
onMounted(start);
onUnmounted(stop);
let googlePromise;
function ensureGoogleScript(client) {
  if (googlePromise) return googlePromise;
  googlePromise = new Promise((resolve, reject) => {
    const prior = document.querySelector('script[data-ace-adsense]');
    if (prior) { if (prior.dataset.loaded === 'true') resolve(); else {prior.addEventListener('load',resolve,{once:true});prior.addEventListener('error',reject,{once:true});} return; }
    const script = document.createElement('script');
    script.async = true; script.dataset.aceAdsense = 'true';
    script.src = `https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=${encodeURIComponent(client)}`;
    script.crossOrigin = 'anonymous';
    script.addEventListener('load',()=>{script.dataset.loaded='true';resolve();},{once:true});
    script.addEventListener('error',reject,{once:true}); document.head.appendChild(script);
  });
  return googlePromise;
}
async function allowThirdParty() {
  if (!props.ad) return;
  consent.value = true;
  if (props.ad.provider !== 'google') return;
  if (!/^ca-pub-\d{16}$/.test(props.ad.google_client||'') || !/^\d{8,20}$/.test(props.ad.google_slot||'')) return;
  try { await nextTick(); await ensureGoogleScript(props.ad.google_client); if (adElement.value) (window.adsbygoogle=window.adsbygoogle||[]).push({}); }
  catch { googleError.value = true; }
}
const external = computed(()=>Boolean(props.ad?.destination_url && /^https?:\/\//i.test(props.ad.destination_url)));
</script>
<template>
  <aside v-if="ad" class="ace-home-ad" :class="{'ace-home-ad-half':placement.startsWith('before_plans')}" aria-label="Publicidade">
    <div v-if="ad.provider==='direct' && active" class="ace-home-ad-gallery" @mouseenter="stop" @mouseleave="start" @focusin="stop" @focusout="start">
      <a v-if="ad.destination_url" class="ace-home-ad-media" :href="ad.destination_url" :target="external?'_blank':undefined" :rel="external?'noopener noreferrer sponsored nofollow':'sponsored nofollow'" :aria-label="ad.headline||'Conhecer anunciante'">
        <img :src="active.url" :alt="ad.headline || 'Publicidade'" loading="lazy" />
      </a>
      <div v-else class="ace-home-ad-media"><img :src="active.url" :alt="ad.headline || 'Publicidade'" loading="lazy" /></div>
      <template v-if="images.length>1">
        <button type="button" class="ace-ad-arrow prev" aria-label="Anúncio anterior" @click="advance(-1)"><ChevronLeft :size="19"/></button>
        <button type="button" class="ace-ad-arrow next" aria-label="Próximo anúncio" @click="advance(1)"><ChevronRight :size="19"/></button>
        <div class="ace-ad-dots"><button v-for="(_,idx) in images" :key="idx" type="button" :class="{selected:current===idx}" :aria-label="`Ver imagem ${idx+1}`" :aria-current="current===idx?'true':undefined" @click="current=idx;start()"/></div>
      </template>
    </div>
    <div v-else-if="ad.provider==='google' || ad.provider==='html'" class="ace-home-ad-embed">
      <button v-if="!consent" type="button" @click="allowThirdParty">Carregar publicidade <small>Este conteúdo pode se conectar a serviços de terceiros.</small></button>
      <span v-else-if="googleError" role="status">Não foi possível carregar o anúncio.</span>
      <ins v-else-if="ad.provider==='google'" ref="adElement" class="adsbygoogle" style="display:block;width:100%;min-height:95px" :data-ad-client="ad.google_client" :data-ad-slot="ad.google_slot" data-ad-format="auto" data-full-width-responsive="true" />
      <iframe v-else title="Publicidade incorporada" class="ace-home-ad-iframe" :src="`/api/v1/public/ads/${ad.id}/embed`" sandbox="allow-scripts allow-popups" referrerpolicy="no-referrer" loading="lazy" />
    </div>
  </aside>
</template>
