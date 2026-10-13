<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    // 1. TAMBAHKAN discount_id dan discount_amount ke dalam array ini
    protected $fillable = [
        'user_id', 'discount_id', 'customer_id', // Tambah customer_id
        'invoice_number', 'total_price', 'discount_amount', 'pay_amount',
        'payment_status', 'due_date' // Tambah status dan jatuh tempo
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function details() 
    {
        return $this->hasMany(TransactionDetail::class);
    }

    // 2. TAMBAHKAN RELASI INI
    public function discount()
    {
        return $this->belongsTo(Discount::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}