<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'phone', 'address', 'total_debt'];

    public function transactions()
    {
        // Satu pelanggan bisa memiliki banyak transaksi
        return $this->hasMany(Transaction::class);
    }
}