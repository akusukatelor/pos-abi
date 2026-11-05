<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceMarginLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_transaction_id',
        'changed_by',
        'old_margin_amount',
        'new_margin_amount',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'old_margin_amount' => 'decimal:2',
            'new_margin_amount' => 'decimal:2',
        ];
    }

    public function serviceTransaction(): BelongsTo
    {
        return $this->belongsTo(ServiceTransaction::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
