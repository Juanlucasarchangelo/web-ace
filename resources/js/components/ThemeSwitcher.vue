<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Sun, Moon, Monitor, ChevronDown, Check } from 'lucide-vue-next';
import { activeTheme, themeMode, setThemeMode } from '../lib/theme';

const menuOpen = ref(false);
const container = ref(null);
const options = [
    { id: 'light', label: 'Claro', icon: Sun },
    { id: 'dark', label: 'Escuro', icon: Moon },
    { id: 'system', label: 'Automático', icon: Monitor },
];
const currentIcon = computed(() => activeTheme.value === 'dark' ? Moon : Sun);
const currentLabel = computed(() => options.find(item => item.id === themeMode.value)?.label || 'Automático');

function choose(mode) {
    setThemeMode(mode);
    menuOpen.value = false;
}
function handlePointer(event) {
    if (menuOpen.value && container.value && !container.value.contains(event.target)) menuOpen.value = false;
}
function handleKey(event) {
    if (event.key === 'Escape') menuOpen.value = false;
}
onMounted(() => {
    document.addEventListener('pointerdown', handlePointer);
    document.addEventListener('keydown', handleKey);
});
onBeforeUnmount(() => {
    document.removeEventListener('pointerdown', handlePointer);
    document.removeEventListener('keydown', handleKey);
});
</script>

<template>
    <div ref="container" class="ace-theme-control">
        <button
            type="button" class="ace-theme-trigger"
            :aria-expanded="menuOpen" aria-haspopup="true"
            aria-label="Selecionar aparência do ACE"
            :title="`Aparência: ${currentLabel}`"
            @click="menuOpen = !menuOpen"
        >
            <component :is="currentIcon" :size="17" :stroke-width="1.8" />
            <ChevronDown :size="13" :stroke-width="1.8" />
        </button>
        <div v-if="menuOpen" class="ace-theme-menu" aria-label="Preferência de aparência">
            <div class="ace-theme-menu-title">APARÊNCIA</div>
            <button
                v-for="option in options" :key="option.id" type="button"
                class="ace-theme-option" :class="{ selected: themeMode === option.id }"
                :aria-pressed="themeMode === option.id"
                @click="choose(option.id)"
            >
                <component :is="option.icon" :size="17" :stroke-width="1.8" />
                <span>{{ option.label }}</span>
                <Check v-if="themeMode === option.id" :size="16" class="ace-theme-check" />
            </button>
            <div class="ace-theme-menu-hint">O automático acompanha o tema do sistema.</div>
        </div>
    </div>
</template>
