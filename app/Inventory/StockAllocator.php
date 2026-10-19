<?php

declare(strict_types=1);

namespace App\Inventory;

use App\Enums\StockMovementKind;
use App\Exceptions\ApiException;
use App\Models\StockLot;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Reserves, releases and sells stock, first-expiring lot first (FEFO).
 *
 * Callers wrap these calls in their own transaction (e.g. placing an order),
 * so stock and the order commit or roll back together.
 */
final readonly class StockAllocator
{
    public function __construct(private StockLedger $ledger) {}

    /**
     * Reserves the given quantities or nothing at all.
     *
     * All candidate lots are locked in ID order, the same order every
     * transaction uses, so concurrent orders queue instead of deadlocking.
     * The whole allocation is computed before anything is written.
     *
     * @param  array<string, int>  $quantities  ordered variant ID => units
     * @return list<Allocation>
     *
     * @throws ApiException INSUFFICIENT_STOCK
     */
    public function reserve(array $quantities, string $reference): array
    {
        $this->assertInTransaction();
        ksort($quantities);

        $needs = $this->expandKits($quantities);
        $componentIds = array_values(array_unique(array_column($needs, 'variant')));

        /** @var Collection<int, StockLot> $lots */
        $lots = StockLot::query()
            ->whereIn('variant_id', $componentIds)
            ->sellable()
            ->whereColumn('quantity_on_hand', '>', 'quantity_reserved')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $free = $lots->mapWithKeys(fn (StockLot $lot): array => [$lot->id => $lot->available()])->all();
        $fefo = $lots->sortBy([['expires_on', 'asc'], ['id', 'asc']])->groupBy('variant_id');

        $allocations = [];
        $short = [];
        foreach ($needs as ['for' => $for, 'variant' => $variant, 'quantity' => $quantity]) {
            foreach ($fefo->get($variant, []) as $lot) {
                $take = min($quantity, $free[$lot->id]);
                if ($take > 0) {
                    $free[$lot->id] -= $take;
                    $quantity -= $take;
                    $allocations[] = new Allocation($lot->id, $variant, $for, $take);
                }
            }
            if ($quantity > 0) {
                $short[$for] = true;
            }
        }

        if ($short !== []) {
            throw InsufficientStock::for($this->shortages($quantities, array_keys($short)));
        }

        $this->apply($allocations, StockMovementKind::Reserved, $reference, onHand: 0, reserved: 1);

        return $allocations;
    }

    /**
     * Gives reserved units back (order cancelled or expired).
     *
     * @param  iterable<Allocation>  $allocations
     */
    public function release(iterable $allocations, string $reference): void
    {
        $this->assertInTransaction();
        $this->apply([...$allocations], StockMovementKind::Released, $reference, onHand: 0, reserved: -1);
    }

    /**
     * Turns reserved units into sold ones (order paid).
     *
     * @param  iterable<Allocation>  $allocations
     */
    public function sell(iterable $allocations, string $reference): void
    {
        $this->assertInTransaction();
        $this->apply([...$allocations], StockMovementKind::Sold, $reference, onHand: -1, reserved: -1);
    }

    /**
     * One need per (ordered variant, stock-holding variant): a regular
     * variant needs itself, a kit needs each component times its quantity.
     *
     * @param  array<string, int>  $quantities
     * @return list<array{for: string, variant: string, quantity: int}>
     */
    private function expandKits(array $quantities): array
    {
        $components = DB::table('kit_components')
            ->whereIn('kit_variant_id', array_keys($quantities))
            ->orderBy('component_variant_id')
            ->get(['kit_variant_id', 'component_variant_id', 'quantity'])
            ->groupBy('kit_variant_id');

        $needs = [];
        foreach ($quantities as $variant => $quantity) {
            $parts = $components->get($variant);
            if ($parts === null) {
                $needs[] = ['for' => $variant, 'variant' => $variant, 'quantity' => $quantity];

                continue;
            }
            /** @var object{component_variant_id: string, quantity: int} $part */
            foreach ($parts as $part) {
                $needs[] = ['for' => $variant, 'variant' => $part->component_variant_id, 'quantity' => $quantity * $part->quantity];
            }
        }

        return $needs;
    }

    /**
     * @param  array<string, int>  $quantities
     * @param  list<string>  $shortVariants
     * @return list<array{variant_id: string, requested: int, available: int}>
     */
    private function shortages(array $quantities, array $shortVariants): array
    {
        $available = DB::table('variant_stock')->whereIn('variant_id', $shortVariants)->pluck('available', 'variant_id');

        return array_map(fn (string $variant): array => [
            'variant_id' => $variant,
            'requested' => $quantities[$variant],
            'available' => is_numeric($n = $available[$variant] ?? 0) ? (int) $n : 0,
        ], $shortVariants);
    }

    /**
     * Applies allocations lot by lot, in ID order, with one ledger entry
     * per lot.
     *
     * @param  array<Allocation>  $allocations
     */
    private function apply(array $allocations, StockMovementKind $kind, string $reference, int $onHand, int $reserved): void
    {
        $perLot = [];
        foreach ($allocations as $allocation) {
            $perLot[$allocation->lotId] = ($perLot[$allocation->lotId] ?? 0) + $allocation->quantity;
        }
        ksort($perLot);

        foreach ($perLot as $lotId => $quantity) {
            $lot = StockLot::query()->lockForUpdate()->findOrFail($lotId);
            $lot->quantity_on_hand += $onHand * $quantity;
            $lot->quantity_reserved += $reserved * $quantity;
            $lot->save();

            $this->ledger->record($lot, $kind, onHand: $onHand * $quantity, reserved: $reserved * $quantity, reference: $reference);
        }
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Stock changes must run inside the caller\'s transaction.');
        }
    }
}
