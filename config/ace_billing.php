<?php

return [
    // Desabilitado até que haja credenciais, domínio HTTPS, webhook e dados do fornecedor.
    'enabled' => env('ACE_PAYMENTS_ENABLED', false),
    'access_token' => env('MERCADOPAGO_ACCESS_TOKEN'),
    'webhook_secret' => env('MERCADOPAGO_WEBHOOK_SECRET'),
    'seller_id' => env('MERCADOPAGO_SELLER_ID'),
    'environment' => env('MERCADOPAGO_ENV', 'sandbox'), // sandbox|production
    'public_url' => env('ACE_BILLING_PUBLIC_URL', ''), // URL HTTPS pública da aplicação
    'legal_name' => env('ACE_LEGAL_NAME', ''),
    'legal_document' => env('ACE_LEGAL_CNPJ', ''),
    'legal_address' => env('ACE_LEGAL_ADDRESS', ''),
    'privacy_contact' => env('ACE_PRIVACY_CONTACT', ''),
    'support_contact' => env('ACE_SUPPORT_CONTACT', ''),
    'legal_version' => '2026-11-08',
    'pricing_version' => \App\Support\BillingPlans::VERSION,
];
