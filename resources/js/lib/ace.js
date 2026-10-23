import { http } from './http';

export function serverError(error) {
    const body = error?.response?.data;
    if (body?.errors) return Object.values(body.errors).flat().join(' ');
    return body?.message || 'Não foi possível concluir a operação. Tente novamente.';
}

export async function getAllClients() {
    const result = [];
    let page = 1;
    while (page <= 50) {
        const { data } = await http.get('/clients', { params: { page } });
        result.push(...data.data.data);
        if (page >= data.data.last_page) break;
        page += 1;
    }
    return result;
}
export const clientStatus = { active: 'Ativo', inactive: 'Inativo' };
export const siteStatus = { active: 'Ativo', inactive: 'Inativo', maintenance: 'Manutenção' };
export const roleNames = { owner: 'Proprietário', admin: 'Administrador', member: 'Membro', viewer: 'Leitor' };
export const cloudNames = {
    google_drive: 'Google Drive', icloud: 'iCloud', mega: 'MEGA', s3: 'Amazon S3',
    onedrive: 'OneDrive', dropbox: 'Dropbox', other: 'Outro',
};
export const categoryNames = { email: 'E-mails', store: 'Loja virtual', social: 'Redes sociais', hosting: 'Hospedagem', ftp: 'FTP', other: 'Outros acessos' };
export const fmtDate = (date) => date ? new Date(date).toLocaleDateString('pt-BR') : '—';
