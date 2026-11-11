<?php

/** Central server-side registry for ACE IA context. Register every new screen here. */
return [
    'workspace' => [
        'dashboard' => ['Visão geral', 'Resumo de clientes, sites, acessos e equipe, com painel de conversa da ACE IA.'],
        'clientes' => ['Clientes', 'Cadastre e consulte clientes, contatos, documentos e endereços da sua própria empresa.'],
        'sites' => ['Sites', 'Gerencie domínios, DNS, hospedagem e acessos técnicos dos sites da empresa.'],
        'cofre' => ['Cofre de acessos', 'Acessos protegidos por serviço. Revelação de segredos requer autorização e auditoria; a IA não revela senhas.'],
        'ia' => ['ACE IA', 'Converse, consulte dados permitidos e prepare cadastros que exigem confirmação explícita.'],
        'empresas' => ['Empresas', 'Selecione ou gerencie os workspaces aos quais sua conta tem acesso.'],
        'equipe' => ['Usuários e permissões', 'O Master gerencia os membros. O limite de vagas é definido pelo DEV.'],
        'atividade' => ['Auditoria', 'Consulte os eventos auditados no workspace.'],
        'nuvem' => ['Integrações em nuvem', 'Gerencie vínculos Google Drive, estado das conexões e arquivos autorizados.'],
        'assinatura' => ['Plano e pagamento', 'Consulte seu plano, origem da cobrança, situação, data de vencimento e renove pelo Mercado Pago. O Master escolhe plano e periodicidade; a liberação paga exige pagamento verificado.'],
        'assinatura_bloqueada' => ['Assinatura pendente', 'O acesso ao workspace está temporariamente restrito. O Master pode contratar ou renovar, mas a reativação exige pagamento confirmado pelo Mercado Pago.'],
    ],
    'dev' => [
        'dev-tenants' => ['Empresas', 'Metadados das empresas, vagas e estado de cobrança. É proibido abrir conteúdo interno de clientes, sites e Drive.'],
        'dev-provision' => ['Criar empresas e usuários', 'O DEV cria workspaces, contas Master e integrantes autorizados, sem precisar de SMTP. A criação é auditada e não comprova pagamento.'],
        'dev-users' => ['Todos os usuários', 'Lista de identidades e vínculos com equipes, sem acesso às informações privadas das empresas.'],
        'dev-issues' => ['Problemas e logs', 'Erros operacionais sanitizados de Google Drive e pagamentos, com histórico e estado de resolução.'],
        'dev-audit' => ['Ações do DEV', 'Registro de ações administrativas do DEV sem dados privados.'],
        'dev-affiliates' => ['Afiliados e cupons', 'Cadastre afiliados vinculados a usuários, emita cupons de desconto, acompanhe cadastros atribuídos, pedidos e vendas confirmadas. Sem acesso ao conteúdo privado dos workspaces.'],
        'dev-plans' => ['Planos e preços', 'Edite títulos, descrições, vantagens e preços mensais e anuais dos planos Free, Cloud e Pro. Somente novas cobranças usam os novos preços. O identificador técnico do plano é imutável.'],
    ],
];
