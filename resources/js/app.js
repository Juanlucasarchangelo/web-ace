import { createApp } from 'vue';
import { createVuetify } from 'vuetify';
import * as components from 'vuetify/components';
import * as directives from 'vuetify/directives';
import 'vuetify/styles';
import '@mdi/font/css/materialdesignicons.css';
import '../css/app.css';
import App from './App.vue';

const vuetify = createVuetify({
    components,
    directives,
    theme: {
        defaultTheme: 'vault',
        themes: {
            vault: {
                dark: false,
                colors: {
                    primary: '#174d60',
                    secondary: '#0e1826',
                    accent: '#b8f2d8',
                    surface: '#ffffff',
                    error: '#bf3750',
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
