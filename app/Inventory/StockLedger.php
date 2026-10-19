<?php

declare(strict_types=1);

namespace App\Inventory;

use App\Enums\AdjustmentReason;
use App\Enums\StockMovementKind;
use App\Exceptions\ApiException;
use App\Models\ProductVariant;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Receiving and correcting stock. Every change is written to the
 * stock_movements ledger in the same transaction.
 */
final class StockLedger
{
    /**
     * Adds units of a lot. Receiving more of a known lot code tops it up; its
     * expiry date must match, since a lot code identifies one production run.
     */
    public function receive(ProductVariant $variant, string $lotCode, Carbon $expiresOn, int $quantity, ?User $by): StockLot
    {
        if ($variant->components()->exists()) {
            throw new ApiException(422, 'KIT_HAS_NO_STOCK', 'Kits have no stock of their own: receive their components instead.');
        }

        return DB::transaction(function () use ($variant, $lotCode, $expiresOn, $quantity, $by): StockLot {
            $lot = StockLot::query()
                ->where('variant_id', $variant->id)
                ->where('lot_code', $lotCode)
                ->lockForUpdate()
                ->first();

            if ($lot === null) {
                $lot = StockLot::query()->create([
                    'variant_id' => $variant->id,
                    'lot_code' => $lotCode,
                    'expires_on' => $expiresOn,
                    'quantity_on_hand' => $quantity,
                ]);
            } elseif (! $lot->expires_on->isSameDay($expiresOn)) {
                throw new ApiException(422, 'LOT_EXPIRY_MISMATCH', "Lot {$lotCode} was received with expiry {$lot->expires_on->toDateString()}.");
            } else {
                $lot->increment('quantity_on_hand', $quantity);
            }

            $this->record($lot, StockMovementKind::Received, onHand: $quantity, by: $by);

            return $lot;
        });
    }

    /**
     * Corrects the units on hand of a lot (breakage, count errors, returns).
     * Reserved units belong to orders and cannot be adjusted away.
     */
    public function adjust(StockLot $lot, int $delta, AdjustmentReason $reason, ?string $note, ?User $by): StockLot
    {
        return DB::transaction(function () use ($lot, $delta, $reason, $note, $by): StockLot {
            $lot = StockLot::query()->lockForUpdate()->findOrFail($lot->id);

            if ($lot->quantity_on_hand + $delta < $lot->quantity_reserved) {
                throw new ApiException(409, 'STOCK_RESERVED', 'Units reserved by orders cannot be adjusted away.', [
                    ['on_hand' => $lot->quantity_on_hand, 'reserved' => $lot->quantity_reserved],
                ]);
            }

            $lot->increment('quantity_on_hand', $delta);
            $this->record($lot, StockMovementKind::Adjusted, onHand: $delta, by: $by,
                reason: trim($reason->value.' '.($note ?? '')));

            return $lot;
        });
    }

    public function record(
        StockLot $lot,
        StockMovementKind $kind,
        int $onHand = 0,
        int $reserved = 0,
        ?User $by = null,
        ?string $reason = null,
        ?string $reference = null,
    ): void {
        StockMovement::query()->create([
            'lot_id' => $lot->id,
            'kind' => $kind,
            'on_hand_delta' => $onHand,
            'reserved_delta' => $reserved,
            'reason' => $reason,
            'user_id' => $by?->id,
            'reference' => $reference,
        ]);
    }
}
