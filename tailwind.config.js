/** @type {import('tailwindcss').Config} */
export default {
    content: ['./resources/**/*.blade.php', './resources/**/*.vue', './resources/**/*.js'],
    corePlugins: { preflight: false }, // Vuetify mantém seu próprio reset
    theme: {
        extend: {
            fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] },
            colors: { ink: '#111B2C', ocean: '#1B667E', mint: '#C6F3DC' },
        },
    },
    plugins: [],
};
