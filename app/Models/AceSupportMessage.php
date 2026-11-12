<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AceSupportMessage extends Model
{
    protected $guarded = [];
    public function author() { return $this->belongsTo(User::class, 'user_id'); }
}
