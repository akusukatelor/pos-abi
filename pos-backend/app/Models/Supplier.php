<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $fillable = ['name', 'phone', 'address', 'contact_person'];

    public function stockMovements()
    {
        // Satu supplier bisa memiliki banyak riwayat barang masuk (kulakan)
        return $this->hasMany(StockMovement::class);
    }
}
