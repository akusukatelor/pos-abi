<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    // Izinkan mass-assignment (opsional, tapi mempermudah saat insert data)
protected $fillable = ['name'];

public function products()
{
    return $this->hasMany(Product::class);
}
}
