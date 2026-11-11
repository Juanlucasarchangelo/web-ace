<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class AceSignupApplication extends Model
{
    use HasUuids;

    protected $table = 'ace_signup_applications';
    protected $guarded = [];
    protected $hidden = ['password_hash', 'email_verification_hash', 'acceptance_ip_hash'];

    protected function casts(): array
    {
        return ['accepted_at' => 'datetime', 'email_verified_at' => 'datetime', 'email_verification_sent_at' => 'datetime'];
    }
}
