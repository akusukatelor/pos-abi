<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductRestockSetting extends Model
{
    protected $fillable = [
        'product_id',
        'sales_period_days',
        'lead_time_days',
        'safety_stock',
        'review_interval_days',
        'manual_minimum_stock',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'sales_period_days' => 'integer',
            'lead_time_days' => 'integer',
            'safety_stock' => 'integer',
            'review_interval_days' => 'integer',
            'manual_minimum_stock' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
