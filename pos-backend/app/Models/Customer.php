<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'phone', 'address', 'total_debt'];

    protected function casts(): array
    {
        return ['total_debt' => 'decimal:2'];
    }

    public function account()
    {
        return $this->hasOne(CustomerAccount::class);
    }

    public function debtPayments()
    {
        return $this->hasMany(CustomerDebtPayment::class);
    }

    public function transactions()
    {
        // Satu pelanggan bisa memiliki banyak transaksi
        return $this->hasMany(Transaction::class);
    }

    public function loyaltyEntries(): \Illuminate\Database\Eloquent\Relations\HasMany
{
    return $this->hasMany(LoyaltyPointTransaction::class, 'customer_id');
}
}