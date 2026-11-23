<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerDebtPaymentAllocation extends Model
{
    protected $fillable = ['customer_debt_payment_id', 'transaction_id', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function payment()
    {
        return $this->belongsTo(CustomerDebtPayment::class, 'customer_debt_payment_id');
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }
}
