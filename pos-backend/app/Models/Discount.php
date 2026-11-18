<?php

// app/Models/Discount.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Discount extends Model
{
    protected $fillable = ['name', 'type', 'value', 'start_date', 'end_date', 'is_active'];

    // WAJIB DITAMBAHKAN AGAR CARBON BISA MEM-PARSE TANGGAL OTOMATIS
    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
        'value' => 'float',
    ];

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }
}