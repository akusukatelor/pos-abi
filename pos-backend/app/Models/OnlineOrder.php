<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OnlineOrder extends Model
{
    protected $fillable = [
        'customer_account_id', 'customer_id', 'order_number', 'status',
        'payment_method', 'payment_reference', 'payment_status', 'total_amount', 'processed_by',
        'transaction_id', 'completed_at', 'cancelled_at', 'cancellation_reason',
        'idempotency_key', 'idempotency_hash',
    ];

    protected $hidden = ['idempotency_key', 'idempotency_hash'];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'completed_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    public function items()
    {
        return $this->hasMany(OnlineOrderItem::class);
    }

    public function customerAccount()
    {
        return $this->belongsTo(CustomerAccount::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }
}