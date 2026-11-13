<script setup>
import {
  ArrowRight, ArrowUpRight, Building2, Check, CheckCircle2, ChevronDown,
  Cloud, Fingerprint, FolderKanban, KeyRound, LockKeyhole, Menu,
  Search, ShieldCheck, Sparkles, UsersRound, X,
} from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { http } from '../lib/http';
import ThemeSwitcher from '../components/ThemeSwitcher.vue';
import MonoSponsorLogo from '../components/MonoSponsorLogo.vue';
import AceHomeAd from '../components/AceHomeAd.vue';

const mobileMenu = ref(false);
const billingCycle = ref('monthly');
const plans = ref([]);
const plansLoading = ref(true);
const pricingError = ref('');
const sponsors = ref([]);
const homeAds = ref({});
const activeFeature = ref('credentials');

const featureTabs = [
  {
    id: 'credentials', icon: KeyRound, eyebrow: 'ACESSOS', title: 'Credenciais sob controle',
    description: 'Os acessos ficam vinculados aos clientes e sites certos. A equipe encontra o que precisa, com as permissões definidas por você.',
    previewTitle: 'Acessos organizados', previewHint: 'Visão ilustrativa do ambiente ACE',
    entries: [
      { icon: LockKeyhole, title: 'Credenciais dos sites', subtitle: 'Informações relacionadas a cada cliente', tag: 'Protegido' },
      { icon: Fingerprint, title: 'Visualização controlada', subtitle: 'Revelação conforme permissão de acesso', tag: 'Permissões' },
      { icon: ShieldCheck, title: 'Acesso responsável', subtitle: 'Estrutura para trabalhar em equipe', tag: 'Controle' },
    ],
  },
  {
    id: 'clients', icon: FolderKanban, eyebrow: 'ORGANIZAÇÃO', title: 'Clientes e sites no mesmo fluxo',
    description: 'Organize os ambientes por cliente, com sites, serviços e acessos relacionados. Menos tempo procurando informações espalhadas.',
    previewTitle: 'Estrutura por cliente', previewHint: 'Visão ilustrativa do ambiente ACE',
    entries: [
      { icon: Building2, title: 'Empresas e clientes', subtitle: 'Organização centralizada da carteira', tag: 'Clientes' },
      { icon: Layers3, title: 'Sites e serviços', subtitle: 'Cada ambiente com seu contexto', tag: 'Sites' },
      { icon: Cloud, title: 'Arquivos conectados', subtitle: 'Integração com Google Drive', tag: 'Arquivos' },
    ],
  },
  {
    id: 'team', icon: UsersRound, eyebrow: 'COLABORAÇÃO', title: 'A equipe certa, com o acesso certo',
    description: 'Distribua responsabilidades por perfil e mantenha as informações separadas por empresa. Cada pessoa acessa apenas o que pode utilizar.',
    previewTitle: 'Gestão de equipe', previewHint: 'Visão ilustrativa do ambiente ACE',
    entries: [
      { icon: UsersRound, title: 'Equipe da empresa', subtitle: 'Pessoas reunidas em um workspace', tag: 'Equipe' },
      { icon: ShieldCheck, title: 'Permissões por perfil', subtitle: 'Master, Administrador e Membro', tag: 'Papéis' },
      { icon: CheckCircle2, title: 'Acesso sob medida', subtitle: 'Cada função com suas permissões', tag: 'Acesso' },
    ],
  },
];
const selectedFeature = computed(() => featureTabs.find((tab) => tab.id === activeFeature.value) || featureTabs[0]);

async function loadPlans() {
  plansLoading.value = true;
  pricingError.value = '';
  try {
    const { data } = await http.get('/billing/plans');
    plans.value = Array.isArray(data?.data?.plans) ? data.data.plans : [];
    if (!plans.value.length) pricingError.value = 'Os planos estão temporariamente indisponíveis.';
  } catch {
    pricingError.value = 'Não foi possível carregar os planos agora.';
  } finally {
    plansLoading.value = false;
  }
}

onMounted(() => {
  // Publicidade e patrocinadores são opcionais e nunca bloqueiam o cadastro ou os planos.
  http.get('/public/sponsors').then(({ data }) => {
    sponsors.value = Array.isArray(data?.data) ? data.data : [];
  }).catch(() => {});
  http.get('/public/ads').then(({ data }) => {
    homeAds.value = data?.data && typeof data.data === 'object' ? data.data : {};
  }).catch(() => {});
  loadPlans();
});

function money(value) {
  return Number(value || 0).toLocaleString('pt-BR', {
    style: 'currency', currency: 'BRL', minimumFractionDigits: 0, maximumFractionDigits: 2,
  });
}
function closeMenu() { mobileMenu.value = false; }
</script>

<template>
  <div class="ace-public ace-home-v4">
    <a class="ace-h4-skip" href="#conteudo-principal">Pular para o conteúdo</a>

    <header class="public-header ace-h4-header">
      <a href="/" class="public-brand ace-h4-brand" aria-label="ACE — página inicial" @click="closeMenu">
        <span class="public-brand-icon"><KeyRound :size="20" /></span>
        <strong>ace<span>.</span></strong>
      </a>
      <nav id="ace-h4-nav" class="public-nav ace-h4-nav" :class="{ open: mobileMenu }" aria-label="Navegação principal">
        <a href="#recursos" @click="closeMenu">Plataforma</a>
        <a href="#como-funciona" @click="closeMenu">Como funciona</a>
        <a href="#planos" @click="closeMenu">Planos</a>
        <a v-if="sponsors.length" href="#parceiros" @click="closeMenu">Parceiros</a>
        <a class="ace-h4-mobile-cta" href="/contratar?plano=free&ciclo=monthly" @click="closeMenu">Começar gratuitamente <ArrowRight :size="16" /></a>
      </nav>
      <div class="public-header-actions ace-h4-header-actions">
        <ThemeSwitcher />
        <a class="ace-h4-signin" href="/login">Entrar</a>
        <a class="ace-h4-header-cta" href="/contratar?plano=free&ciclo=monthly">Criar conta <ArrowUpRight :size="16" /></a>
        <button type="button" class="ace-h4-menu" :aria-expanded="mobileMenu" aria-controls="ace-h4-nav" :aria-label="mobileMenu ? 'Fechar menu' : 'Abrir menu'" @click="mobileMenu = !mobileMenu"><X v-if="mobileMenu" :size="21" /><Menu v-else :size="21" /></button>
      </div>
    </header>

    <main id="conteudo-principal">
      <section class="ace-h4-hero" aria-labelledby="ace-h4-hero-heading">
        <div class="ace-h4-container ace-h4-hero-grid">
          <div class="ace-h4-hero-copy">
            <div class="ace-h4-eyebrow"><span class="ace-h4-signal"></span> SUA OPERAÇÃO EM UM SÓ LUGAR</div>
            <h1 id="ace-h4-hero-heading">A informação certa.<br /><span>Nas mãos certas.</span></h1>
            <p>O workspace para organizar clientes, sites, acessos e equipe. Tudo conectado, com clareza para quem administra e controle para quem utiliza.</p>
            <div class="ace-h4-hero-actions">
              <a href="/contratar?plano=free&ciclo=monthly" class="ace-h4-button ace-h4-button-primary">Começar gratuitamente <ArrowRight :size="18" /></a>
              <a href="#recursos" class="ace-h4-button ace-h4-button-outline">Explorar a plataforma <ArrowUpRight :size="17" /></a>
            </div>
            <p class="ace-h4-hero-note"><CheckCircle2 :size="16" /> Plano gratuito disponível. Comece sem pagamento.</p>
          </div>
          <div class="ace-h4-showcase" aria-label="Representação ilustrativa da interface do ACE">
            <div class="ace-h4-preview-window">
              <div class="ace-h4-preview-top"><span class="ace-h4-preview-logo"><KeyRound :size="15" /></span><b>ace</b><span class="ace-h4-preview-breadcrumb">Workspace / Visão geral</span><span class="ace-h4-preview-dots"><i></i><i></i><i></i></span></div>
              <div class="ace-h4-preview-body">
                <aside class="ace-h4-preview-nav"><span class="ace-h4-preview-nav-title">MENU</span><span class="is-selected"><FolderKanban :size="14" /> Visão geral</span><span><Building2 :size="14" /> Clientes</span><span><KeyRound :size="14" /> Acessos</span><span><Cloud :size="14" /> Arquivos</span><span><UsersRound :size="14" /> Equipe</span></aside>
                <div class="ace-h4-preview-content">
                  <div class="ace-h4-preview-heading"><div><span>SEU WORKSPACE</span><strong>Bom trabalho começa com organização.</strong></div><span class="ace-h4-preview-check"><Check :size="14" /> Estruturado</span></div>
                  <div class="ace-h4-preview-search"><Search :size="15" /><span>Encontre clientes, sites e acessos</span><span>⌘ K</span></div>
                  <div class="ace-h4-preview-items"><div><span class="ace-h4-mini-icon"><Building2 :size="18" /></span><span><b>Clientes e sites</b><small>Tudo relacionado</small></span><ArrowUpRight :size="15" /></div><div><span class="ace-h4-mini-icon"><LockKeyhole :size="18" /></span><span><b>Credenciais</b><small>Acesso por permissão</small></span><ArrowUpRight :size="15" /></div><div><span class="ace-h4-mini-icon"><UsersRound :size="18" /></span><span><b>Equipe</b><small>Funções definidas</small></span><ArrowUpRight :size="15" /></div></div>
                </div>
              </div>
            </div>
            <div class="ace-h4-preview-label"><span class="ace-h4-preview-label-dot"></span> PRÉVIA ILUSTRATIVA DO WORKSPACE ACE</div>
          </div>
        </div>
        <div class="ace-h4-container ace-h4-hero-baseline"><div><ShieldCheck :size="18" /><span>Controle de acesso por perfil</span></div><div><FolderKanban :size="18" /><span>Organização por empresa</span></div><div><Cloud :size="18" /><span>Google Drive integrado</span></div></div>
      </section>

      <section id="recursos" class="ace-h4-features ace-h4-section" aria-labelledby="ace-h4-features-heading">
        <div class="ace-h4-container">
          <div class="ace-h4-section-heading"><div><span class="ace-h4-overline">UMA PLATAFORMA, MAIS CLAREZA</span><h2 id="ace-h4-features-heading">Menos informação espalhada.<br /><span>Mais trabalho resolvido.</span></h2></div><p>Do primeiro cliente ao acesso da equipe, tudo segue uma estrutura simples e fácil de consultar.</p></div>
          <div class="ace-h4-feature-stage">
            <div class="ace-h4-feature-switcher" role="tablist" aria-label="Conheça os recursos do ACE">
              <button v-for="tab in featureTabs" :key="tab.id" type="button" role="tab" :id="`ace-h4-tab-${tab.id}`" :aria-selected="activeFeature === tab.id" aria-controls="ace-h4-feature-panel" :class="{ 'is-active': activeFeature === tab.id }" @click="activeFeature = tab.id">
                <span class="ace-h4-tab-icon"><component :is="tab.icon" :size="20" /></span><span><small>{{ tab.eyebrow }}</small><strong>{{ tab.title }}</strong></span><ArrowUpRight :size="18" class="ace-h4-tab-arrow" />
              </button>
              <div class="ace-h4-feature-foot"><Sparkles :size="18" /><span>Mais recursos disponíveis conforme o plano escolhido.</span></div>
            </div>
            <div id="ace-h4-feature-panel" class="ace-h4-feature-panel" role="tabpanel" :aria-labelledby="`ace-h4-tab-${selectedFeature.id}`">
              <div class="ace-h4-feature-panel-top"><div><span>VISÃO DO RECURSO</span><h3>{{ selectedFeature.previewTitle }}</h3></div><span><CheckCircle2 :size="16" /> Fluxo organizado</span></div>
              <div class="ace-h4-feature-rows"><div v-for="entry in selectedFeature.entries" :key="entry.title"><span class="ace-h4-feature-row-icon"><component :is="entry.icon" :size="19" /></span><span class="ace-h4-feature-row-text"><strong>{{ entry.title }}</strong><small>{{ entry.subtitle }}</small></span><small class="ace-h4-feature-row-tag">{{ entry.tag }}</small></div></div>
              <div class="ace-h4-feature-panel-bottom"><span>{{ selectedFeature.previewHint }}</span><div class="ace-h4-feature-bullets"><i></i><i></i><i></i></div></div>
            </div>
          </div>
          <p class="ace-h4-feature-summary">{{ selectedFeature.description }}</p>
          <div class="ace-h4-integrations"><div><Cloud :size="21" /><span><strong>Google Drive</strong><small>Arquivos conectados</small></span></div><div><Sparkles :size="21" /><span><strong>ACE IA</strong><small>Consultas assistidas</small></span></div><div><ShieldCheck :size="21" /><span><strong>Permissões</strong><small>Acessos sob controle</small></span></div></div>
        </div>
      </section>

      <div v-if="homeAds.after_features" class="ace-h4-ad-zone ace-h4-ad-one"><AceHomeAd placement="after_features" :ad="homeAds.after_features" /></div>

      <section id="como-funciona" class="ace-h4-how ace-h4-section" aria-labelledby="ace-h4-how-heading"><div class="ace-h4-container">
        <div class="ace-h4-how-intro"><div><span class="ace-h4-overline">SIMPLES DE COMEÇAR</span><h2 id="ace-h4-how-heading">Seu espaço de trabalho.<br /><span>Do seu jeito.</span></h2><p>Você começa com o essencial. A estrutura cresce junto com a operação.</p><a href="/contratar?plano=free&ciclo=monthly">Criar meu workspace <ArrowRight :size="17" /></a></div><div class="ace-h4-how-steps"><div><span>01</span><div><strong>Crie seu ambiente</strong><p>Cadastre sua empresa e escolha o plano.</p></div><CheckCircle2 :size="19" /></div><div><span>02</span><div><strong>Organize clientes e acessos</strong><p>Relacione sites, serviços e credenciais.</p></div><CheckCircle2 :size="19" /></div><div><span>03</span><div><strong>Convide sua equipe</strong><p>Defina perfis e permissões para cada pessoa.</p></div><CheckCircle2 :size="19" /></div></div></div>
      </div></section>

      <div v-if="homeAds.after_control" class="ace-h4-ad-zone ace-h4-ad-two"><AceHomeAd placement="after_control" :ad="homeAds.after_control" /></div>

      <section v-if="sponsors.length" id="parceiros" class="ace-h4-partners" aria-labelledby="ace-h4-partners-heading"><div class="ace-h4-container ace-h4-partners-layout"><div class="ace-h4-partners-text"><span class="ace-h4-overline">QUEM APOIA O ACE</span><h2 id="ace-h4-partners-heading">Nossos parceiros.</h2><p>Marcas que apoiam o desenvolvimento da plataforma.</p></div><div class="ace-h4-sponsor-list"><component :is="sponsor.website_url ? 'a' : 'div'" v-for="sponsor in sponsors" :key="sponsor.id" class="ace-h4-sponsor" :href="sponsor.website_url || undefined" :target="sponsor.website_url ? '_blank' : undefined" :rel="sponsor.website_url ? 'noopener noreferrer sponsored nofollow' : undefined" :aria-label="sponsor.name"><MonoSponsorLogo :src="sponsor.logo_url" :alt="`Logotipo de ${sponsor.name}`" /></component></div></div></section>

      <!-- Posição 03: esquerda e direita são campanhas independentes, lado a lado. -->
      <div v-if="homeAds.before_plans_left || homeAds.before_plans_right" class="ace-h4-ad-zone ace-h4-ad-pair-zone"><div class="ace-h4-ad-pair" :class="{ 'is-single': !homeAds.before_plans_left || !homeAds.before_plans_right }"><AceHomeAd v-if="homeAds.before_plans_left" placement="before_plans_left" :ad="homeAds.before_plans_left" /><AceHomeAd v-if="homeAds.before_plans_right" placement="before_plans_right" :ad="homeAds.before_plans_right" /></div></div>

      <section id="planos" class="ace-h4-pricing ace-h4-section" aria-labelledby="ace-h4-pricing-heading"><div class="ace-h4-container">
        <div class="ace-h4-pricing-top"><div><span class="ace-h4-overline">PLANOS ACE</span><h2 id="ace-h4-pricing-heading">Comece pequeno.<br /><span>Cresça com controle.</span></h2><p>Escolha os recursos que fazem sentido para seu momento.</p></div><div class="ace-h4-cycle" role="group" aria-label="Periodicidade do plano"><button type="button" :class="{ selected: billingCycle === 'monthly' }" :aria-pressed="billingCycle === 'monthly'" @click="billingCycle = 'monthly'">Mensal</button><button type="button" :class="{ selected: billingCycle === 'annual' }" :aria-pressed="billingCycle === 'annual'" @click="billingCycle = 'annual'">Anual</button></div></div>
        <div v-if="plansLoading" class="ace-h4-loading" role="status" aria-live="polite"><div></div><div></div><div></div><p>Carregando os planos atuais…</p></div>
        <div v-else-if="pricingError" class="ace-h4-pricing-error" role="alert"><span>{{ pricingError }}</span><button type="button" @click="loadPlans">Tentar novamente <ArrowRight :size="16" /></button></div>
        <div v-else class="ace-h4-plans"><article v-for="plan in plans" :key="plan.id" class="ace-h4-plan" :class="{ 'is-featured': plan.id === 'cloud' }"><div v-if="plan.id === 'cloud'" class="ace-h4-plan-popular">DESTAQUE</div><div class="ace-h4-plan-name">ACE {{ plan.name }}</div><p class="ace-h4-plan-desc">{{ plan.description }}</p><div class="ace-h4-price">{{ plan.id === 'free' ? 'Grátis' : money(billingCycle === 'annual' ? plan.annual_cents / 1200 : plan.monthly_cents / 100) }}<small v-if="plan.id !== 'free'">/mês</small></div><div class="ace-h4-plan-billing">{{ plan.id === 'free' ? 'Sem cobrança' : billingCycle === 'annual' ? 'Cobrança anual: ' + money(plan.annual_cents / 100) : 'Cobrança mensal: ' + money(plan.monthly_cents / 100) }}</div><a :href="`/contratar?plano=${plan.id}&ciclo=${billingCycle}`" class="ace-h4-plan-button">{{ plan.id === 'free' ? 'Começar gratuitamente' : 'Escolher ACE ' + plan.name }} <ArrowRight :size="17" /></a><div class="ace-h4-plan-divider"></div><ul><li v-for="feature in plan.features" :key="feature"><Check :size="16" /> {{ feature }}</li></ul></article></div>
        <p class="ace-h4-pricing-legal">Na contratação anual, o valor total de 12 meses é cobrado de uma vez. Pagamento via Mercado Pago (Pix, cartão ou boleto, conforme disponibilidade). Não há recorrência automática nesta etapa. Consulte os <a href="/termos">Termos</a> e a <a href="/privacidade">Política de Privacidade</a>.</p>
      </div></section>

      <section class="ace-h4-faq ace-h4-section" aria-labelledby="ace-h4-faq-heading"><div class="ace-h4-container ace-h4-faq-grid"><div><span class="ace-h4-overline">PRECISA SABER MAIS?</span><h2 id="ace-h4-faq-heading">Respostas sem complicação.</h2><p>O essencial para você começar com segurança.</p></div><div class="ace-h4-faq-list"><details><summary>Posso começar sem pagar? <ChevronDown :size="19" /></summary><p>Sim. O ACE tem um plano gratuito para conhecer a plataforma e organizar as primeiras informações.</p></details><details><summary>Minha equipe pode acessar o mesmo workspace? <ChevronDown :size="19" /></summary><p>Sim. Você pode convidar usuários e configurar permissões conforme os limites e recursos do plano.</p></details><details><summary>O ACE se conecta ao Google Drive? <ChevronDown :size="19" /></summary><p>Sim. A plataforma oferece integração com contas Google Drive autorizadas, conforme o plano contratado.</p></details><details><summary>Posso trocar de plano? <ChevronDown :size="19" /></summary><p>Sim. Os recursos disponíveis acompanham o plano e as condições da assinatura.</p></details></div></div></section>

      <section class="ace-h4-final" aria-labelledby="ace-h4-final-heading"><div class="ace-h4-container ace-h4-final-layout"><div><span class="ace-h4-overline">UM BOM COMEÇO É TER CLAREZA</span><h2 id="ace-h4-final-heading">Organize hoje.<br />Trabalhe melhor amanhã.</h2><p>Centralize sua operação sem complicar o dia a dia.</p></div><a href="/contratar?plano=free&ciclo=monthly" class="ace-h4-button ace-h4-button-primary">Começar gratuitamente <ArrowRight :size="19" /></a></div></section>
    </main>

    <div v-if="homeAds.footer" class="ace-h4-ad-zone ace-h4-ad-footer"><AceHomeAd placement="footer" :ad="homeAds.footer" /></div>
    <footer class="public-footer ace-h4-footer"><div class="ace-h4-container ace-h4-footer-inner"><a href="/" class="public-brand ace-h4-brand" aria-label="ACE — início"><span class="public-brand-icon"><KeyRound :size="17" /></span><strong>ace<span>.</span></strong></a><span>© {{ new Date().getFullYear() }} ACE · Gestão empresarial de credenciais.</span><nav aria-label="Links legais"><a href="/termos">Termos</a><a href="/privacidade">Privacidade</a><a href="/login">Área do cliente <ArrowUpRight :size="14" /></a></nav></div></footer>
  </div>
</template>
