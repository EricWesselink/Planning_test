<?php

namespace App\Services;

use App\Enums\VoucherPriceSource;
use App\Enums\WorkUnit;
use App\Models\Voucher;
use App\Models\VoucherLine;
use App\Models\Worker;
use App\Models\WorkItem;
use App\Models\WorkOrder;
use Illuminate\Support\Collection;

class VoucherPriceResolver
{
    /**
     * @param  Collection<int, WorkOrder>  $orders
     * @return array{price: float|null, source: VoucherPriceSource}
     */
    public function resolve(
        Worker $worker,
        ?WorkItem $item,
        WorkUnit $unit,
        ?Voucher $opdracht,
        Collection $orders,
    ): array {
        $fromOpdracht = $this->fromOpdracht($opdracht, $item, $unit);
        if ($fromOpdracht !== null) {
            return ['price' => $fromOpdracht, 'source' => VoucherPriceSource::Voucher];
        }

        $fromOrder = $this->fromOrder($orders, $item);
        if ($fromOrder !== null) {
            return ['price' => $fromOrder, 'source' => VoucherPriceSource::Order];
        }

        $fromRate = $this->fromRate($worker, $item, $unit);
        if ($fromRate !== null) {
            return ['price' => $fromRate, 'source' => VoucherPriceSource::Rate];
        }

        return ['price' => null, 'source' => VoucherPriceSource::Manual];
    }

    private function fromOpdracht(?Voucher $opdracht, ?WorkItem $item, WorkUnit $unit): ?float
    {
        if ($opdracht === null) {
            return null;
        }

        $lines = $opdracht->lines;
        if ($item !== null) {
            $match = $lines->first(
                fn (VoucherLine $line): bool => (int) $line->work_item_id === (int) $item->id
            );
            if ($match !== null) {
                return (float) $match->unit_price;
            }

            $key = $item->specialtyKey();
            $match = $lines->first(
                fn (VoucherLine $line): bool => $line->unit === $unit && $line->specialty_key === $key
            );
            if ($match !== null) {
                return (float) $match->unit_price;
            }
        }

        return null;
    }

    /** @param Collection<int, WorkOrder> $orders */
    private function fromOrder(Collection $orders, ?WorkItem $item): ?float
    {
        if ($item === null) {
            return null;
        }

        $match = $orders->first(
            fn (WorkOrder $order): bool => (int) $order->work_item_id === (int) $item->id
        );

        return $match !== null ? (float) $match->unit_price : null;
    }

    private function fromRate(Worker $worker, ?WorkItem $item, WorkUnit $unit): ?float
    {
        if ($item !== null) {
            $key = $item->specialtyKey();
            $match = $worker->rates->first(
                fn ($rate): bool => $rate->specialty === $key && $rate->unit === $unit
            );
            if ($match !== null) {
                return (float) $match->unit_price;
            }
        }

        if ($unit === WorkUnit::Hours) {
            $hourly = $worker->hourlyRate();
            if ($hourly !== null) {
                return (float) $hourly->unit_price;
            }
        }

        return null;
    }
}
