<script setup>
import { onMounted, ref, watch } from 'vue';

const props = defineProps({ src: { type: String, required: true }, alt: { type: String, default: 'Logotipo' } });
const canvas = ref(null);
const failed = ref(false);
let request = 0;

// Normaliza tamanho VISUAL, mantém proporção e elimina cores e fundos planos.
// A logo original fica intacta no armazenamento (sem conversão destrutiva).
function renderLogo() {
  const current = ++request;
  failed.value = false;
  if (!props.src) { failed.value = true; return; }
  const image = new Image();
  image.onload = () => {
    if (current !== request || !canvas.value) return;
    try {
      const ratio = Math.min(1, 1000 / Math.max(image.naturalWidth, image.naturalHeight));
      const w = Math.max(1, Math.round(image.naturalWidth * ratio));
      const h = Math.max(1, Math.round(image.naturalHeight * ratio));
      const source = document.createElement('canvas'); source.width = w; source.height = h;
      const ctx = source.getContext('2d', { willReadFrequently: true });
      ctx.drawImage(image, 0, 0, w, h);
      const pixels = ctx.getImageData(0, 0, w, h);
      const data = pixels.data;
      const corners = [[0,0],[w-1,0],[0,h-1],[w-1,h-1]].map(([x,y]) => {
        const i = (y*w+x)*4; return [data[i],data[i+1],data[i+2],data[i+3]];
      });
      let transparentPixels = 0;
      for (let i=3; i<data.length; i+=4) if (data[i] < 245) transparentPixels++;
      const hasAlpha = transparentPixels > w*h*.005;
      const avg = [0,1,2].map(c => corners.reduce((sum,p)=>sum+p[c],0)/4);
      const similarCorners = corners.every(p => p[3] > 240 && Math.max(...avg.map((v,i)=>Math.abs(p[i]-v))) < 35);
      const removeMatte = !hasAlpha && similarCorners;
      let left=w, top=h, right=-1, bottom=-1;
      for (let y=0; y<h; y++) for (let x=0; x<w; x++) {
        const i = (y*w+x)*4;
        const originalAlpha = data[i+3];
        let alpha = originalAlpha;
        if (removeMatte) {
          const distance = Math.max(Math.abs(data[i]-avg[0]),Math.abs(data[i+1]-avg[1]),Math.abs(data[i+2]-avg[2]));
          alpha = Math.round(Math.min(255,Math.max(0,(distance-12)*255/70)));
        }
        data[i] = data[i+1] = data[i+2] = 0;
        data[i+3] = alpha;
        if (alpha > 22) { left=Math.min(left,x); right=Math.max(right,x); top=Math.min(top,y); bottom=Math.max(bottom,y); }
      }
      if (right < left) { failed.value = true; return; }
      ctx.putImageData(pixels,0,0);
      const output = canvas.value; const out = output.getContext('2d');
      output.width = 360; output.height = 152;
      out.clearRect(0,0,360,152);
      const innerW=right-left+1, innerH=bottom-top+1;
      const scale = Math.min(334/innerW,130/innerH);
      const drawW=innerW*scale, drawH=innerH*scale;
      out.drawImage(source,left,top,innerW,innerH,(360-drawW)/2,(152-drawH)/2,drawW,drawH);
    } catch { failed.value = true; }
  };
  image.onerror = () => { if (current===request) failed.value=true; };
  image.src = props.src;
}
onMounted(renderLogo);
watch(() => props.src, renderLogo);
</script>
<template>
  <div class="ace-mono-logo" role="img" :aria-label="alt">
    <canvas v-show="!failed" ref="canvas" aria-hidden="true"></canvas>
    <img v-if="failed" :src="src" alt="" aria-hidden="true" loading="lazy" />
  </div>
</template>
