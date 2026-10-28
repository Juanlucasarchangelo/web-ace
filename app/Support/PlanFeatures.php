<?php

namespace App\Support;

use App\Models\Tenant;

final class PlanFeatures
{
    public const FREE = 'free';
    public const CLOUD = 'cloud';
    public const PRO = 'pro';

    public static function cloud(Tenant $tenant): bool
    {
        return in_array($tenant->plan ?? self::FREE, [self::CLOUD, self::PRO], true);
    }

    public static function ai(Tenant $tenant): bool
    {
        return ($tenant->plan ?? self::FREE) === self::PRO;
    }
}
