<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class CustomerAccount extends Authenticatable
{
    use HasApiTokens;

    protected $fillable = ['customer_id', 'email', 'password', 'is_active'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'is_active' => 'boolean'];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function onlineOrders()
    {
        return $this->hasMany(OnlineOrder::class);
    }

    public function loyaltyEntries()
    {
        return $this->hasMany(LoyaltyPointTransaction::class);
    }
}
