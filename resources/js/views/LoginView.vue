<script setup>
import { computed, ref } from 'vue';
import { ArrowRight, ArrowUpRight, Building2, Check, ChevronRight, CircleHelp, Eye, EyeOff, Fingerprint, KeyRound, LockKeyhole, Mail, ShieldCheck, Sparkles } from 'lucide-vue-next';
import { http, ensureCsrf } from '../lib/http';

const props = defineProps({ initialError: { type: String, default: '' } });
const emit = defineEmits(['authenticated']);
const email = ref('');
const password = ref('');
const showPassword = ref(false);
const submitting = ref(false);
const errorMessage = ref('');
const attempted = ref(false);
const validEmail = computed(() => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim()));
const validForm = computed(() => validEmail.value && password.value.length > 0);

async function signIn() {
    attempted.value = true;
    errorMessage.value = '';
    if (!validForm.value || submitting.value) return;
    submitting.value = true;
    try {
        await ensureCsrf();
        const { data } = await http.post('/auth/login', {
            email: email.value.trim(),
            password: password.value,
        });
        password.value = '';
        emit('authenticated', data.data);
    } catch (error) {
        if (error.response?.status === 419) {
            errorMessage.value = 'A página expirou. Atualize-a e tente novamente.';
        } else if (error.response?.status === 429) {
            errorMessage.value = 'Muitas tentativas. Aguarde um minuto antes de tentar novamente.';
        } else if ([401, 422].includes(error.response?.status)) {
            errorMessage.value = error.response?.data?.errors?.email?.[0] || 'Não foi possível autenticar. Verifique seus dados.';
        } else {
            errorMessage.value = 'Não foi possível conectar ao servidor. Tente novamente.';
        }
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <main class="auth-layout">
        <section class="story-pane" aria-label="Sobre a plataforma">
            <div class="story-glow story-glow-a"></div>
            <div class="story-glow story-glow-b"></div>
            <div class="story-grid"></div>
            <div class="story-content">
                <header class="story-top">
                    <div class="brand brand-light" aria-label="ACE, gestão de credenciais">
                        <span class="brand-symbol" aria-hidden="true"><KeyRound :size="23" :stroke-width="2.2" /></span>
                        <span class="brand-word">ace<span class="brand-period">.</span></span>
                    </div>
                    <span class="brand-category">CREDENTIAL PLATFORM <span class="category-dot"></span> B2B</span>
                </header>

                <div class="story-main">
                    <div class="eyebrow"><span class="eyebrow-spark"></span> SUA OPERAÇÃO, MAIS PROTEGIDA</div>
                    <h1>O controle certo.<br>Em <span class="headline-accent">cada acesso.</span></h1>
                    <p class="story-intro">Uma nova forma de organizar identidades, permissões e credenciais da sua empresa. Simples de operar. Projetado para crescer.</p>
                    <div class="benefits">
                        <span><ShieldCheck :size="17" :stroke-width="1.8" /> Estrutura corporativa</span>
                        <span><Fingerprint :size="17" :stroke-width="1.8" /> Acesso protegido</span>
                    </div>
                </div>

                <div class="concept" aria-label="Ilustração da futura plataforma">
                    <div class="concept-main">
                        <div class="concept-header">
                            <div class="concept-title"><span class="concept-icon"><LockKeyhole :size="17" /></span><div><strong>Seu workspace</strong><small>Governança de acesso</small></div></div>
                            <span class="concept-live"><span></span> PRIVADO</span>
                        </div>
                        <div class="concept-cards">
                            <div class="concept-card">
                                <div class="concept-card-icon icon-pearl"><Building2 :size="18" /></div>
                                <span>Organizações</span>
                                <div class="concept-lines"><i></i><i></i></div>
                            </div>
                            <div class="concept-card">
                                <div class="concept-card-icon icon-mint"><KeyRound :size="18" /></div>
                                <span>Credenciais</span>
                                <div class="concept-lines"><i></i><i></i></div>
                            </div>
                            <div class="concept-card">
                                <div class="concept-card-icon icon-blue"><ShieldCheck :size="18" /></div>
                                <span>Permissões</span>
                                <div class="concept-lines"><i></i><i></i></div>
                            </div>
                        </div>
                        <div class="concept-footer"><span><Check :size="14" /> Design centrado em segurança</span><span>PRÉVIA CONCEITUAL</span></div>
                    </div>
                    <div class="floating-seal"><div><ShieldCheck :size="21" :stroke-width="1.9" /></div><span>Proteção em<br><strong>primeiro lugar.</strong></span></div>
                </div>

                <footer class="story-footer"><span>© {{ new Date().getFullYear() }} ACE. Plataforma corporativa.</span><span><span class="footer-dot"></span> SOFTWARE AS A SERVICE</span></footer>
            </div>
        </section>

        <section class="form-pane" aria-labelledby="signin-title">
            <div class="form-shell">
                <div class="mobile-brand brand"><span class="brand-symbol"><KeyRound :size="23" /></span><span class="brand-word">ace<span class="brand-period">.</span></span></div>
                <div class="form-topline"><span class="private-badge"><span class="private-indicator"></span> AMBIENTE RESTRITO</span><span class="form-top-icon"><Sparkles :size="18" /></span></div>

                <div class="form-heading">
                    <div class="mini-line"></div>
                    <h2 id="signin-title">Bem-vindo de volta<span class="heading-dot">.</span></h2>
                    <p>Entre com suas credenciais corporativas para acessar o seu ambiente de trabalho.</p>
                </div>

                <div v-if="props.initialError" role="alert" class="auth-error">{{ props.initialError }}</div>
                <div v-if="errorMessage" role="alert" class="auth-error">{{ errorMessage }}</div>

                <form class="signin-form" novalidate @submit.prevent="signIn">
                    <div class="form-group">
                        <label for="email">E-mail corporativo</label>
                        <v-text-field
                            id="email" v-model="email" type="email" name="email"
                            placeholder="seu.nome@empresa.com.br" autocomplete="username"
                            variant="outlined" density="comfortable" rounded="lg"
                            :error="attempted && !validEmail" :disabled="submitting"
                            :aria-invalid="attempted && !validEmail" hide-details
                        ><template #prepend-inner><Mail :size="18" :stroke-width="1.7" class="field-icon" /></template></v-text-field>
                        <span v-if="attempted && !validEmail" class="field-error">Informe um e-mail válido.</span>
                    </div>

                    <div class="form-group password-group">
                        <div class="field-label-row"><label for="password">Senha</label><span class="field-hint">ACESSO PRIVADO</span></div>
                        <v-text-field
                            id="password" v-model="password" name="password"
                            :type="showPassword ? 'text' : 'password'"
                            placeholder="Digite sua senha" autocomplete="current-password"
                            variant="outlined" density="comfortable" rounded="lg"
                            :error="attempted && !password.length" :disabled="submitting"
                            :aria-invalid="attempted && !password.length" hide-details
                        >
                            <template #prepend-inner><LockKeyhole :size="18" :stroke-width="1.7" class="field-icon" /></template>
                            <template #append-inner>
                                <button type="button" class="eye-button" :aria-label="showPassword ? 'Ocultar senha' : 'Mostrar senha'" :aria-pressed="showPassword" @click="showPassword = !showPassword"><EyeOff v-if="showPassword" :size="18" /><Eye v-else :size="18" /></button>
                            </template>
                        </v-text-field>
                        <span v-if="attempted && !password.length" class="field-error">Informe sua senha.</span>
                    </div>
                    <v-btn class="submit-button" color="primary" type="submit" block :height="58" :loading="submitting" :disabled="submitting">
                        <span>Acessar minha conta</span><ArrowRight :size="19" :stroke-width="1.8" />
                    </v-btn>
                    <div class="form-assurance"><ShieldCheck :size="17" :stroke-width="1.7" /><span>Conexão protegida. Seus dados são tratados com segurança.</span></div>
                </form>

                <div class="invitation-panel"><div class="invitation-icon"><CircleHelp :size="20" :stroke-width="1.8" /></div><div><strong>Precisa de acesso?</strong><p>As contas são provisionadas pela sua organização. Entre em contato com seu administrador.</p></div><ChevronRight :size="19" class="invitation-arrow" /></div>

                <footer class="form-footer"><span>Plataforma de uso corporativo</span><span><LockKeyhole :size="13" /> Acesso autenticado</span></footer>
            </div>
        </section>
    </main>
</template>
