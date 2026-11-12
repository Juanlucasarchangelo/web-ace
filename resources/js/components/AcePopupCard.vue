<script setup>
import { ArrowRight, ArrowUpRight, X, Megaphone } from 'lucide-vue-next';
const props = defineProps({ item: { type: Object, required: true }, preview: { type: Boolean, default: false } });
const emit = defineEmits(['close']);
const isExternal = url => /^https?:\/\//i.test(url || '');
</script>
<template>
  <article class="ace-popup-card" :class="'ace-popup-kind-'+item.type" :aria-label="item.title || 'Comunicado ACE'">
    <button type="button" class="ace-popup-close" aria-label="Fechar comunicado" @click="emit('close')"><X :size="19"/></button>
    <template v-if="item.type === 'image'">
      <a v-if="item.cta_url && item.image_url" :href="preview ? undefined : item.cta_url" :target="isExternal(item.cta_url) ? '_blank' : '_self'" rel="noopener noreferrer nofollow sponsored" class="ace-popup-image-link" @click="preview && $event.preventDefault()" :aria-label="item.cta_label || 'Abrir anúncio'">
        <img :src="item.image_url" :alt="item.title || 'Anúncio em imagem'"/>
      </a>
      <img v-else-if="item.image_url" class="ace-popup-image-only" :src="item.image_url" :alt="item.title || 'Anúncio em imagem'"/>
      <div v-else class="ace-popup-image-placeholder">Imagem do anúncio</div>
      <span v-if="item.cta_url" class="ace-popup-image-caption">{{ item.cta_label || 'Saiba mais' }} <ArrowUpRight :size="15"/></span>
    </template>
    <template v-else-if="item.type === 'notice'">
      <div class="ace-popup-notice-icon"><Megaphone :size="21"/></div>
      <div class="ace-popup-notice-copy"><strong>{{item.title}}</strong><p v-if="item.description">{{item.description}}</p></div>
      <a v-if="item.cta_url" class="ace-popup-notice-cta" :href="preview ? undefined : item.cta_url" :target="isExternal(item.cta_url) ? '_blank' : '_self'" rel="noopener noreferrer nofollow sponsored" @click="preview && $event.preventDefault()">{{item.cta_label || 'Ver detalhes'}} <ArrowRight :size="15"/></a>
    </template>
    <template v-else>
      <div class="ace-popup-content" :class="{'has-image':!!item.image_url}">
        <div class="ace-popup-copy">
          <div class="ace-popup-kicker"><span></span>{{item.type==='offer' ? (item.badge_text || 'OPORTUNIDADE ACE') : 'UMA MENSAGEM DO ACE'}}</div>
          <h2>{{item.title}}</h2>
          <p v-if="item.description">{{item.description}}</p>
          <a v-if="item.cta_url" class="ace-popup-cta" :href="preview ? undefined : item.cta_url" :target="isExternal(item.cta_url) ? '_blank' : '_self'" rel="noopener noreferrer nofollow sponsored" @click="preview && $event.preventDefault()">{{item.cta_label || 'Saiba mais'}} <ArrowUpRight :size="18"/></a>
          <span class="ace-popup-footer">ACE · COMUNICAÇÃO OFICIAL</span>
        </div>
        <div v-if="item.image_url" class="ace-popup-photo"><img :src="item.image_url" :alt="item.title || 'Imagem ilustrativa'"/></div>
      </div>
    </template>
  </article>
</template>
