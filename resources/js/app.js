import { createApp } from 'vue';
import { createVuetify } from 'vuetify';
import * as components from 'vuetify/components';
import * as directives from 'vuetify/directives';
import 'vuetify/styles';
import '@mdi/font/css/materialdesignicons.css';
import '../css/app.css';
import App from './App.vue';
import { activeTheme } from './lib/theme';

const vuetify = createVuetify({
    components,
    directives,
    theme: {
        defaultTheme: activeTheme.value === 'dark' ? 'aceDark' : 'aceLight',
        themes: {
            aceLight: {
                dark: false,
                colors: {
                    primary: '#174d60',
                    secondary: '#0e1826',
                    accent: '#b8f2d8',
                    surface: '#ffffff',
                    error: '#bf3750',
                    background: '#f7f9fb',
                    'on-background': '#192d3b',
                    'on-surface': '#192d3b',
                },
            },
            aceDark: {
                dark: true,
                colors: {
                    primary: '#65c8b0',
                    secondary: '#9eb1c0',
                    accent: '#91e0c9',
                    surface: '#1b2937',
                    background: '#101923',
                    error: '#f38b9a',
                    'on-surface': '#e7eff5',
                    'on-background': '#e7eff5',
                    'on-primary': '#0b2525',
                },
            },
        },
    },
    defaults: {
        VBtn: { elevation: 0, rounded: 'lg' },
        VTextField: { variant: 'outlined', density: 'comfortable', hideDetails: 'auto', rounded: 'lg' },
    },
});

createApp(App).use(vuetify).mount('#app');
