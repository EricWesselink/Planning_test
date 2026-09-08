<?php

namespace App\Models;

use App\Enums\VoucherPriceKind;
use App\Enums\VoucherPriceSource;
use App\Enums\WorkUnit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'voucher_id', 'project_area_id', 'work_item_id', 'specialty_key',
    'description', 'quantity', 'unit', 'unit_price', 'amount', 'price_source',
    'price_kind',
])]
class VoucherLine extends Model
{
    protected $attributes = [
        'price_kind' => 'unit',
    ];

    protected function casts(): array
    {
        return [
            'unit' => WorkUnit::class,
            'price_source' => VoucherPriceSource::class,
            'price_kind' => VoucherPriceKind::class,
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(ProjectArea::class, 'project_area_id');
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }

    public function key(): string
    {
        return self::lineKey(
            $this->project_area_id ? (int) $this->project_area_id : null,
            $this->work_item_id ? (int) $this->work_item_id : null,
        );
    }

    public static function lineKey(?int $areaId, ?int $workItemId): string
    {
        return ($areaId ?: 0).'|'.($workItemId ?: 0);
    }
}
