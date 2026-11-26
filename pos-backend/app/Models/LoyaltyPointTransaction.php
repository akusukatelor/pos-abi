<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyPointTransaction extends Model
{
   protected $fillable = [
    'customer_id',
    'customer_account_id',
    'online_order_id',
    'transaction_id',
    'created_by',
    'points_delta',
    'description',
    'reference_key',
];

public function customer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
{
    return $this->belongsTo(Customer::class);
}

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}