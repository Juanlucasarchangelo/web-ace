<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DriveFile extends Model
{
    protected $fillable = [
        'drive_account_id',
        'external_id',
        'name',
        'mime_type',
        'path'
    ];

    public function driveAccount()
    {
        return $this->belongsTo(DriveAccount::class);
    }
}
