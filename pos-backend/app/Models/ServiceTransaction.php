<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_type_id',
        'user_id',
        'reference_number',
        'service_at',
        'nominal_amount',
        'margin_amount',
        'margin_verified_by',
        'margin_verified_at',
        'notes',
        'status',
        'cancelled_by',
        'cancelled_at',
        'cancellation_reason',
        'idempotency_key',
        'idempotency_hash',
    ];

    protected function casts(): array
    {
        return [
            'service_at' => 'datetime',
            'nominal_amount' => 'decimal:2',
            'margin_amount' => 'decimal:2',
            'margin_verified_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function marginVerifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'margin_verified_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function marginLogs(): HasMany
    {
        return $this->hasMany(ServiceMarginLog::class);
    }
}
