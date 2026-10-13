<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    protected $fillable = ['user_id', 'amount', 'category', 'description', 'expense_date'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}