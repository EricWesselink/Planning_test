<?php

namespace App\Models;

use App\Enums\WorkUnit;
use App\Support\Format;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'measurement_form_id', 'sort_order', 'room', 'product', 'brand', 'type',
    'color_number', 'quantity', 'unit', 'underlay', 'skirting', 'steps',
    'profile', 'available_on_site',
])]
class MeasurementFormRow extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'available_on_site' => false,
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'quantity' => 'decimal:2',
            'unit' => WorkUnit::class,
            'available_on_site' => 'boolean',
        ];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(MeasurementForm::class, 'measurement_form_id');
    }

    public function quantityLabel(): string
    {
        if ($this->quantity === null || (float) $this->quantity <= 0) {
            return $this->unit?->label() ?? '';
        }

        $decimals = fmod((float) $this->quantity, 1.0) === 0.0 ? 0 : 2;

        return trim(Format::qty($this->quantity, $decimals).' '.($this->unit?->label() ?? ''));
    }
}
