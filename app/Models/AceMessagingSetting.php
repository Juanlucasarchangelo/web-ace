<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AceMessagingSetting extends Model
{
    protected $table = 'ace_messaging_settings';
    public $incrementing = false;
    protected $guarded = [];
    protected $hidden = ['resend_api_key'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'resend_api_key' => 'encrypted', 'event_preferences' => 'array'];
    }
}
