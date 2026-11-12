<?php

namespace App\Services\Messaging;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Transactional outbox. Call inside the business transaction; no network calls here. */
final class MailEvents
{
    public static function record(string $key, string $type, array $payload, ?string $directEmail = null): void
    {
        DB::table('ace_mail_events')->insertOrIgnore([
            'event_key' => Str::limit($key, 190, ''),
            'event_type' => $type,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'direct_email' => $directEmail ? Str::lower(trim($directEmail)) : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
