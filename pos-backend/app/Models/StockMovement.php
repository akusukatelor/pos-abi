<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    use HasFactory;

    // 1. TAMBAHKAN supplier_id ke dalam array ini
    protected $fillable = [
        'product_id', 'user_id', 'supplier_id', 'type', 
        'quantity', 'description'
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // 2. TAMBAHKAN RELASI INI
    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }
}