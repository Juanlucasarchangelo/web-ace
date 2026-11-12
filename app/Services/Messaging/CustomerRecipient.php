<?php

namespace App\Services\Messaging;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Uses the email captured at checkout, without exposing a customer settings screen. */
final class CustomerRecipient
{
    public static function forTenant(?Tenant $tenant): ?string
    {
        if (! $tenant) return null;
        $email = self::valid($tenant->billing_notification_email);
        if ($email) return $email;

        // Existing customers: prefer the original email verified at signup.
        $email = DB::table('ace_signup_applications')->where('tenant_id', $tenant->id)
            ->whereNotNull('email_verified_at')->orderByDesc('created_at')->value('email');
        if ($email = self::valid($email)) return $email;

        // Legacy/provisioned tenants without a checkout: use the active owner.
        $email = DB::table('tenant_user as tu')->join('users as u', 'u.id', '=', 'tu.user_id')
            ->where('tu.tenant_id', $tenant->id)->where('tu.role', 'owner')
            ->where('tu.is_active', true)->where('u.is_active', true)
            ->whereNotNull('u.email_verified_at')->orderBy('tu.created_at')->value('u.email');
        return self::valid($email);
    }

    public static function valid(?string $email): ?string
    {
        $email = Str::lower(trim((string) $email));
        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }
}
