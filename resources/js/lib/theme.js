import { computed, ref, watch } from 'vue';

// Preferência por navegador: não altera dados de usuários, empresas ou credenciais.
const STORAGE_KEY = 'ace.appearance.v1';
const validModes = ['light', 'dark', 'system'];

function savedMode() {
    try {
        const saved = window.localStorage.getItem(STORAGE_KEY);
        return validModes.includes(saved) ? saved : 'system';
    } catch {
        return 'system';
    }
}

const systemMedia = window.matchMedia?.('(prefers-color-scheme: dark)');
const systemDark = ref(Boolean(systemMedia?.matches));
export const themeMode = ref(savedMode());
export const activeTheme = computed(() =>
    themeMode.value === 'system' ? (systemDark.value ? 'dark' : 'light') : themeMode.value
);

export function setThemeMode(mode) {
    if (!validModes.includes(mode)) return;
    themeMode.value = mode;
    try { window.localStorage.setItem(STORAGE_KEY, mode); } catch { /* Navegação privada. */ }
}

function applyTheme() {
    const theme = activeTheme.value;
    document.documentElement.dataset.aceTheme = theme;
    document.documentElement.style.colorScheme = theme;
    const meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', theme === 'dark' ? '#101923' : '#f7f9fb');
}

watch(activeTheme, applyTheme, { immediate: true });

if (systemMedia) {
    const updateSystemTheme = event => { systemDark.value = event.matches; };
    if (systemMedia.addEventListener) systemMedia.addEventListener('change', updateSystemTheme);
    else systemMedia.addListener?.(updateSystemTheme);
}
