<?php

namespace App\Models;

use App\Enums\AdjustmentSubtype;
use App\Enums\MovementStatus;
use App\Enums\MovementType;
use App\Enums\TransferStatus;
use App\Models\Concerns\HasSyncIdentity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Movement extends Model
{
    use HasSyncIdentity;

    protected $table = 'movement';

    protected $fillable = [
        'type', 'adjustment_subtype', 'code', 'status', 'transfer_status',
        'warehouse_id', 'to_warehouse_id',
        'exchange_rate_snapshot', 'exchange_rate_id', 'tax_rate_snapshot',
        'supplier_id', 'original_movement_id',
        'reason', 'reason_void',
        'total_without_tax_usd', 'total_tax_usd', 'total_with_tax_usd',
        'total_without_tax_cup', 'total_tax_cup', 'total_with_tax_cup',
        'created_by_user_id', 'voided_by_user_id', 'voided_at',
        'received_by_user_id', 'received_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => MovementType::class,
            'adjustment_subtype' => AdjustmentSubtype::class,
            'status' => MovementStatus::class,
            'transfer_status' => TransferStatus::class,
            'received_at' => 'datetime',
            'exchange_rate_snapshot' => 'decimal:4',
            'tax_rate_snapshot' => 'decimal:2',
            'total_without_tax_usd' => 'decimal:2',
            'total_tax_usd' => 'decimal:2',
            'total_with_tax_usd' => 'decimal:2',
            'total_without_tax_cup' => 'decimal:2',
            'total_tax_cup' => 'decimal:2',
            'total_with_tax_cup' => 'decimal:2',
            'voided_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(MovementItem::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function exchangeRate(): BelongsTo
    {
        return $this->belongsTo(ExchangeRate::class);
    }

    public function originalMovement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_movement_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_user_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }
}
