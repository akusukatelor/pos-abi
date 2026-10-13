<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    // 1. TAMBAHKAN unit_id ke dalam array ini
    protected $fillable = [
        'category_id', 'unit_id', 'sku_code', 'name', 
        'buy_price', 'sell_price', 'stock'
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    // 2. TAMBAHKAN RELASI INI
    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class);
    }

    public function transactionDetails()
    {
        return $this->hasMany(TransactionDetail::class);
    }
}