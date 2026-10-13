<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    protected $fillable = [
        'user_id', 'opened_at', 'closed_at', 
        'opening_balance', 'closing_balance_system', 'closing_balance_actual', 'status'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
