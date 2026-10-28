<?php

return [
    'google_drive' => [
        'client_id' => env('GOOGLE_DRIVE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_DRIVE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_DRIVE_REDIRECT_URI', env('APP_URL', 'http://127.0.0.1:8088').'/cloud/google/callback'),
        // drive.file = arquivos criados/explicitamente autorizados para o ACE.
        // 'drive' exige verificação adicional para distribuição pública.
        'scope' => env('GOOGLE_DRIVE_SCOPE', 'https://www.googleapis.com/auth/drive.file'),
    ],
];
