<?php

// Keys and provider settings stay server-side; never serialize them to the frontend.
return [
    'provider' => env('ACE_AI_PROVIDER', 'local'),
    'api_key' => env('GEMINI_API_KEY', ''),
    // Paid flagship for highest capability; gracefully fall back to Pro/Flash when access or capacity is limited.
    'model' => env('ACE_AI_MODEL', 'gemini-4-argon'),
    'fallback_models' => env('ACE_AI_FALLBACK_MODELS', 'gemini-3.1-pro-preview,gemini-3.8-flash,gemini-3.5-flash,gemini-3.1-flash-lite'),
];
