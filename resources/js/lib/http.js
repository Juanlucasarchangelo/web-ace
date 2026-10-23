import axios from 'axios';

// Sessão HttpOnly é gerenciada pelo Laravel Sanctum; nenhum token no JS.
export const http = axios.create({
    baseURL: '/api/v1',
    timeout: 15000,
    withCredentials: true,
    withXSRFToken: true,
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
});

export async function ensureCsrf() {
    await http.get('/sanctum/csrf-cookie', { baseURL: '/' });
}
