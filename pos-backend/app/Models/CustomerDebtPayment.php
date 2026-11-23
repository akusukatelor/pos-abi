<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerDebtPayment extends Model
{
    protected $hidden = ['idempotency_key', 'idempotency_hash'];

    protected $fillable = [
        'customer_id', 'user_id', 'amount', 'payment_method',
        'idempotency_key', 'idempotency_hash',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function allocations()
    {
        return $this->hasMany(CustomerDebtPaymentAllocation::class);
    }
}
