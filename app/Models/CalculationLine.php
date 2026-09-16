<?php

namespace App\Models;

use App\Enums\FinishRole;
use App\Enums\QuantitySource;
use App\Enums\WorkUnit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'calculation_id', 'calculation_drawing_id', 'calculation_workbook_id', 'sort_order',
    'room_number', 'room_name', 'product_code', 'product', 'original_product_code', 'original_product',
    'quantity', 'original_quantity', 'excel_quantity', 'excel_product_code', 'excel_product',
    'unit', 'finish_role', 'room_area', 'source', 'found_source', 'note', 'unit_price', 'confirmed_manually',
    'confirmed_at', 'calculation_trace',
])]
class CalculationLine extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'sort_order' => 0,
        'unit' => 'm2',
        'source' => 'review',
        'confirmed_manually' => false,
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'original_quantity' => 'decimal:3',
            'excel_quantity' => 'decimal:3',
            'room_area' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'unit' => WorkUnit::class,
            'finish_role' => FinishRole::class,
            'source' => QuantitySource::class,
            'found_source' => QuantitySource::class,
            'sort_order' => 'integer',
            'confirmed_manually' => 'boolean',
            'confirmed_at' => 'datetime',
        ];
    }

    public function calculation(): BelongsTo
    {
        return $this->belongsTo(Calculation::class);
    }

    public function drawing(): BelongsTo
    {
        return $this->belongsTo(CalculationDrawing::class, 'calculation_drawing_id');
    }

    public function workbook(): BelongsTo
    {
        return $this->belongsTo(CalculationWorkbook::class, 'calculation_workbook_id');
    }

    public function totalKey(): string
    {
        $code = mb_strtolower(trim((string) $this->product_code));
        $unit = $this->unit?->value ?? WorkUnit::SquareMeter->value;
        if ($code !== '') {
            return $code.'|'.$unit;
        }

        return mb_strtolower(trim((string) $this->product)).'|'.$unit;
    }
}
