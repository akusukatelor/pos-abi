<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Discount extends Model
{
    protected $fillable = ['name', 'type', 'value', 'start_date', 'end_date', 'is_active'];

    public function transactions()
    {
        // Jika diskon diterapkan di level struk/transaksi (bukan per item)
        return $this->hasMany(Transaction::class);
    }
}
