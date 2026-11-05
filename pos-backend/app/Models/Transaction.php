<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'customer_id',
        'discount_id',
        'invoice_number',
        'total_price',
        'pay_amount',
        'payment_method',
        'change_amount',
        'discount_amount',
        'payment_status',
        'due_date',
        'qris_verified_by',
        'qris_verified_at',
        'payment_reference',
        'idempotency_key',
        'idempotency_hash',
    ];

    protected function casts(): array
    {
        return [
            'total_price' => 'decimal:2',
            'pay_amount' => 'decimal:2',
            'change_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'due_date' => 'date',
            'qris_verified_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function details()
    {
        return $this->hasMany(TransactionDetail::class);
    }

    public function discount()
    {
        return $this->belongsTo(Discount::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function qrisVerifier()
    {
        return $this->belongsTo(User::class, 'qris_verified_by');
    }
}
